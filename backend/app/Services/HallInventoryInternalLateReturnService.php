<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Separate witnessed physical discovery after an original partial return was
 * independently reviewed. Only ORIGINAL in-transit unresolved counts can move.
 * Testimony and documentary corrections are never stock movements.
 */
final class HallInventoryInternalLateReturnService
{
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $positions) {}

    public function record(User $actor,string $company,string $batchId,string $id,
        string $item,int $good,int $damaged,int $sourceRevision,int $destRevision,
        UploadedFile $proof):string
    {
        foreach(['loan_drafts_enabled','loan_dispatch_enabled','loan_receipt_enabled',
            'loan_returns_enabled','loan_return_acceptance_enabled','internal_late_return_enabled'] as $flag)
            if(!config('inventory_controls.'.$flag))
                throw new ConflictHttpException('Later physical return acceptance is not released.');
        foreach([$company,$batchId,$id,$item] as $value)
            if(!Str::isUuid($value))throw new InvalidArgumentException('Exact original custody UUIDs required.');
        if($good<0||$damaged<0||$good+$damaged<1||$good+$damaged>100000000||
            $sourceRevision<0||$destRevision<0)
            throw new InvalidArgumentException('Positive actual observed quantities and revisions required.');
        $path=$proof->getRealPath();$size=$proof->getSize();
        if(!$proof->isValid()||!$path||!is_file($path)||!is_int($size)||$size<1||
            $size>5242880||!in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/png','image/jpeg'],true))
            throw new InvalidArgumentException('Signed subsequent physical count PDF/photo required.');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Subsequent signed proof could not be read.');
        $sha=hash('sha256',$bytes);
        $fingerprint=hash('sha256',json_encode([$company,$batchId,$id,$item,$good,
            $damaged,$sha,$size,(int)$actor->getKey()],JSON_THROW_ON_ERROR));
        $disk=Storage::disk('local');
        $key='private/hall-internal-late-return/'.$company.'/'.$batchId.'/'.$id;
        return DB::transaction(function()use($actor,$company,$batchId,$id,$item,$good,
            $damaged,$sourceRevision,$destRevision,$bytes,$sha,$size,$fingerprint,$disk,$key):string {
            $batch=DB::table('hall_inventory_internal_loan_return_batches')
                ->where('company_id',$company)->where('id',$batchId)->first();
            if(!$batch)throw new ConflictHttpException('Original signed return batch was not found.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$company)
                ->where('id',$batch->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='internal'||$loan->lending_hall_id!==$batch->source_hall_id||
                $loan->borrowing_hall_id!==$batch->destination_hall_id)
                throw new AuthorizationException('Original INTERNAL return source required.');
            if(DB::table('hall_inventory_internal_loan_closures')->where('company_id',$company)
                ->where('loan_id',$loan->id)->exists()||
               DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$company)
                ->where('loan_id',$loan->id)->exists())
                throw new ConflictHttpException('Final loan receipt prevents later custody movement.');
            $halls=Hall::query()->where('company_id',$company)
                ->whereIn('id',[$loan->lending_hall_id,$loan->borrowing_hall_id])
                ->where('is_active',true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source=$halls->get($loan->lending_hall_id);
            if($halls->count()!==2||!$source||!Auth::check()||
                (int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$source))
                throw new AuthorizationException('Current independent source Owner/Admin required.');
            $accept=DB::table('hall_inventory_internal_loan_return_acceptances')
                ->where('company_id',$company)->where('return_batch_id',$batchId)->first();
            if(!$accept)throw new ConflictHttpException('First physical acceptance must be independently recorded.');
            if((int)$accept->accepted_by===(int)$actor->getKey()||
               (int)$batch->returned_by===(int)$actor->getKey())
                throw new AuthorizationException('New source count needs a separate authorized witness.');
            $line=DB::table('hall_inventory_internal_loan_return_acceptance_lines')
                ->where('company_id',$company)->where('acceptance_id',$accept->id)
                ->where('source_item_id',$item)->first();
            if(!$line||$line->unresolved_quantity<1)
                throw new ConflictHttpException('No original unresolved shipped item exists.');
            foreach([[$batch->evidence_storage_path,$batch->evidence_sha256,$batch->evidence_size],
                [$accept->evidence_storage_path,$accept->evidence_sha256,$accept->evidence_size]]
                as [$oldPath,$oldSha,$oldSize]) {
                if(!$disk->exists((string)$oldPath))
                    throw new ConflictHttpException('Original source custody proof is missing.');
                $oldBytes=$disk->get((string)$oldPath);
                if(!is_string($oldBytes)||strlen($oldBytes)!==(int)$oldSize||
                    !hash_equals((string)$oldSha,hash('sha256',$oldBytes)))
                    throw new ConflictHttpException('Original source custody proof failed integrity check.');
            }
            $old=DB::table('hall_inventory_internal_late_returns')
                ->where('company_id',$company)->where('id',$id)->first();
            if($old) {
                if(!hash_equals((string)$old->fingerprint,$fingerprint)||
                    !$disk->exists((string)$old->evidence_storage_path))
                    throw new ConflictHttpException('Conflicting physical discovery retry.');
                $oldBytes=$disk->get((string)$old->evidence_storage_path);
                if(!is_string($oldBytes)||strlen($oldBytes)!==(int)$old->evidence_size||
                    !hash_equals((string)$old->evidence_sha256,hash('sha256',$oldBytes)))
                    throw new ConflictHttpException('Previously signed discovery proof has changed.');
                return $id;
            }
            $previous=DB::table('hall_inventory_internal_late_returns')
                ->where('company_id',$company)->where('acceptance_id',$accept->id)
                ->where('source_item_id',$item)->get();
            $prior=0;
            foreach($previous as $previousRow) {
                self::verifyRecordedProof($previousRow);
                $prior+=(int)$previousRow->good_quantity+(int)$previousRow->damaged_quantity;
            }
            $quantity=$good+$damaged;
            if($prior+$quantity>(int)$line->unresolved_quantity)
                throw new ConflictHttpException('Previously unresolved shipped quantity was already reconciled.');
            $locks=[[$loan->lending_hall_id,$item],[$loan->borrowing_hall_id,$line->dest_item_id]];
            usort($locks,fn($a,$b)=>strcmp($a[0].'/'.$a[1],$b[0].'/'.$b[1]));
            $locked=[];
            foreach($locks as [$hall,$catalog]) {
                $pos=DB::table('hall_inventory_positions')->where('company_id',$company)
                    ->where('hall_id',$hall)->where('item_id',$catalog)->lockForUpdate()->first();
                if(!$pos)throw new ConflictHttpException('Original position for later physical count is missing.');
                $locked[$hall.'/'.$catalog]=$pos;
            }
            $sourcePos=$locked[$loan->lending_hall_id.'/'.$item];
            $destPos=$locked[$loan->borrowing_hall_id.'/'.$line->dest_item_id];
            if((int)$sourcePos->revision!==$sourceRevision||(int)$destPos->revision!==$destRevision||
                (int)$sourcePos->owned_on_loan<$quantity||(int)$destPos->borrowed_transit<$quantity)
                throw new ConflictHttpException('Stale revision or insufficient physical in-transit custody.');
            if($disk->exists($key))throw new ConflictHttpException('Immutable later signed proof path already occupied.');
            if(!$disk->put($key,$bytes))throw new ConflictHttpException('Later witnessed proof storage failed.');
            try {
                $onDisk=$disk->get($key);
                if(!is_string($onDisk)||strlen($onDisk)!==$size||
                    !hash_equals($sha,hash('sha256',$onDisk)))
                    throw new ConflictHttpException('Later signed proof did not persist intact.');
                $sourceState=$this->state($sourcePos);$destState=$this->state($destPos);
                $sourceRev=$sourceRevision;$goodId=null;$damagedId=null;
                foreach([['return_owned_good',$good],['return_owned_damaged',$damaged]] as [$kind,$qty]) {
                    if(!$qty)continue;
                    $after=$this->positions->apply($sourceState,$kind,$qty);
                    if(DB::table('hall_inventory_positions')->where('id',$sourcePos->id)
                        ->where('company_id',$company)->where('revision',$sourceRev)->update([
                            'owned_usable'=>$after['ownedUsable'],
                            'owned_on_loan'=>$after['ownedOnLoan'],
                            'owned_damaged'=>$after['ownedDamaged'],
                            'revision'=>$sourceRev+1,'updated_at'=>now()])!==1)
                        throw new ConflictHttpException('Concurrent original source stock revision changed.');
                    $movement=(string)Str::uuid();
                    $this->movement($movement,$company,$loan->lending_hall_id,$item,$sourcePos->id,
                        $kind,$qty,$sourceRev,$sourceState,$after,$actor,
                        $id,'Independently witnessed later original return '.$batchId);
                    if($kind==='return_owned_good')$goodId=$movement;else $damagedId=$movement;
                    $sourceState=$after;$sourceRev++;
                }
                $after=$this->positions->apply($destState,'confirm_borrowed_return',$quantity);
                if(DB::table('hall_inventory_positions')->where('id',$destPos->id)
                    ->where('company_id',$company)->where('revision',$destRevision)->update([
                        'borrowed_transit'=>$after['borrowedTransit'],
                        'revision'=>$destRevision+1,'updated_at'=>now()])!==1)
                    throw new ConflictHttpException('Concurrent mapped borrower transit revision changed.');
                $destMovement=(string)Str::uuid();
                $this->movement($destMovement,$company,$loan->borrowing_hall_id,
                    (string)$line->dest_item_id,$destPos->id,'confirm_borrowed_return',$quantity,
                    $destRevision,$destState,$after,$actor,$id,
                    'Independent later source receipt '.$batchId);
                DB::table('hall_inventory_internal_late_returns')->insert([
                    'id'=>$id,'company_id'=>$company,'loan_id'=>$loan->id,'return_batch_id'=>$batchId,
                    'acceptance_id'=>$accept->id,'source_hall_id'=>$loan->lending_hall_id,
                    'source_item_id'=>$item,'dest_item_id'=>$line->dest_item_id,
                    'good_quantity'=>$good,'damaged_quantity'=>$damaged,
                    'good_movement_id'=>$goodId,'damaged_movement_id'=>$damagedId,
                    'dest_movement_id'=>$destMovement,
                    'evidence_sha256'=>$sha,'evidence_size'=>$size,
                    'evidence_storage_path'=>$key,'fingerprint'=>$fingerprint,
                    'accepted_by'=>$actor->getKey()
                ]);
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$company,'actor_id'=>$actor->getKey(),
                    'action'=>'hall_inventory.internal_original_late_return_physically_accepted',
                    'entity_type'=>'hall_inventory_internal_late_return','entity_id'=>$id,
                    'before_values'=>json_encode(['unresolved'=>$line->unresolved_quantity-$prior],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['good'=>$good,'damaged'=>$damaged,
                        'remainingUnresolved'=>$line->unresolved_quantity-$prior-$quantity],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['loanId'=>$loan->id,'batchId'=>$batchId,
                        'sourceItemId'=>$item,'signedProofSha256'=>$sha,
                        'moneyPosted'=>false,'finalClosure'=>false],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $error){$disk->delete($key);throw $error;}
            return $id;
        },3);
    }

    /** Original lending-hall only audited private PDF/photo access. */
    public function privateProof(User $actor,string $company,string $hall,string $id):array
    {
        foreach(['loan_drafts_enabled','loan_returns_enabled',
            'loan_return_acceptance_enabled','internal_late_return_enabled'] as $flag)
            if(!config('inventory_controls.'.$flag))
                throw new ConflictHttpException('Later original physical evidence is not released.');
        foreach([$company,$hall,$id] as $value)
            if(!Str::isUuid($value))throw new InvalidArgumentException('Exact private physical proof UUID required.');
        $source=Hall::query()->where('company_id',$company)->where('id',$hall)
            ->where('is_active',true)->first();
        if(!$source||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$source))
            throw new AuthorizationException('Current original source Owner/Admin required for private receipt.');
        $row=DB::table('hall_inventory_internal_late_returns')
            ->where('company_id',$company)->where('source_hall_id',$hall)
            ->where('id',$id)->first();
        if(!$row)throw new AuthorizationException('Original private later receipt is outside this hall.');
        self::verifyRecordedProof($row);
        $bytes=Storage::disk('local')->get((string)$row->evidence_storage_path);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(!in_array($mime,['application/pdf','image/png','image/jpeg'],true))
            throw new ConflictHttpException('Stored signed late receipt content type failed verification.');
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company,'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.internal_late_original_physical_proof_read',
            'entity_type'=>'hall_inventory_internal_late_return','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode(['loanId'=>$row->loan_id,
                'batchId'=>$row->return_batch_id,'sha256'=>$row->evidence_sha256],JSON_THROW_ON_ERROR),
            'context'=>json_encode(['hallId'=>$hall,'privateRead'=>true],JSON_THROW_ON_ERROR)
        ]);
        return ['bytes'=>$bytes,'mime'=>$mime,
            'name'=>'internal-later-physical-receipt-'.$id.
                ($mime==='application/pdf'?'.pdf':($mime==='image/png'?'.png':'.jpg'))];
    }

    /** Fail closed on documentary or movement tampering before another posting or ledger read. */
    public static function verifyRecordedProof(object $row):void
    {
        $disk=Storage::disk('local');
        $path='private/hall-internal-late-return/'.$row->company_id.'/'
            .$row->return_batch_id.'/'.$row->id;
        if($path!==(string)$row->evidence_storage_path||!$disk->exists($path))
            throw new ConflictHttpException('Original later signed physical proof missing.');
        $bytes=$disk->get($path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
            !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Later physically witnessed proof integrity failed.');
        $acceptLine=DB::table('hall_inventory_internal_loan_return_acceptance_lines')
            ->where('company_id',$row->company_id)->where('acceptance_id',$row->acceptance_id)
            ->where('source_item_id',$row->source_item_id)->first();
        $batch=DB::table('hall_inventory_internal_loan_return_batches')
            ->where('company_id',$row->company_id)->where('id',$row->return_batch_id)->first();
        if(!$batch||!$acceptLine||$batch->loan_id!==$row->loan_id||
            $batch->source_hall_id!==$row->source_hall_id||
            $acceptLine->dest_item_id!==$row->dest_item_id||
            (int)$row->good_quantity+(int)$row->damaged_quantity>
                (int)$acceptLine->unresolved_quantity)
            throw new ConflictHttpException('Later original-loan physical record is not correctly bound.');
        foreach([
            [$row->good_quantity,$row->good_movement_id,$row->source_hall_id,
                $row->source_item_id,'return_owned_good','Independently witnessed later original return '.$row->return_batch_id],
            [$row->damaged_quantity,$row->damaged_movement_id,$row->source_hall_id,
                $row->source_item_id,'return_owned_damaged','Independently witnessed later original return '.$row->return_batch_id],
            [(int)$row->good_quantity+(int)$row->damaged_quantity,
                $row->dest_movement_id,$batch->destination_hall_id,
                $row->dest_item_id,'confirm_borrowed_return',
                'Independent later source receipt '.$row->return_batch_id]
        ] as [$qty,$movementId,$hall,$item,$kind,$reason]) {
            if((int)$qty===0){
                if($movementId!==null)
                    throw new ConflictHttpException('Zero physical quantity referenced an inventory movement.');
                continue;
            }
            if(!$movementId)throw new ConflictHttpException('Signed later count lacks inventory movement.');
            $movement=DB::table('hall_inventory_movement_events')
                ->where('company_id',$row->company_id)->where('hall_id',$hall)
                ->where('item_id',$item)->where('id',$movementId)
                ->where('event_kind',$kind)->where('quantity',$qty)
                ->where('actor_id',$row->accepted_by)->first();
            if(!$movement||$movement->reason!==$reason||
                !hash_equals(hash('sha256',$row->id.$item.$kind),
                    (string)$movement->request_fingerprint))
                throw new ConflictHttpException('Later witnessed physical count has forged movement provenance.');
        }
    }

    private function state(object $p):array {
        return ['ownedUsable'=>(int)$p->owned_usable,'ownedOnLoan'=>(int)$p->owned_on_loan,
            'ownedDamaged'=>(int)$p->owned_damaged,'borrowedUsable'=>(int)$p->borrowed_usable,
            'borrowedTransit'=>(int)$p->borrowed_transit,'borrowedDamaged'=>(int)$p->borrowed_damaged];
    }

    private function movement(string $id,string $company,string $hall,string $item,string $position,
        string $kind,int $qty,int $revision,array $before,array $after,User $actor,
        string $request,string $reason):void {
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>$id,'company_id'=>$company,'hall_id'=>$hall,'item_id'=>$item,
            'position_id'=>$position,'event_kind'=>$kind,'quantity'=>$qty,
            'revision_before'=>$revision,'revision_after'=>$revision+1,
            'request_fingerprint'=>hash('sha256',$request.$item.$kind),
            'reason'=>$reason,'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),'actor_id'=>$actor->getKey()
        ]);
    }
}
