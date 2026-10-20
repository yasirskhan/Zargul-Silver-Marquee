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
 * Outside lending: immutable partner-signed physical handover evidence, then
 * independent owner approval moves OWNED goods to owned-on-loan. Never
 * posts cash, a receivable, a penalty or financial settlement.
 */
final class HallInventoryExternalOutDispatchService {
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $transition) {}
    private function gate():void {
        if(!config('inventory_controls.loan_drafts_enabled') ||
           !config('inventory_controls.external_out_dispatch_enabled'))
            throw new ConflictHttpException('External owned-stock lending is not released.');
    }
    private function identifiers(string ...$values):void {
        foreach($values as $id)if(!Str::isUuid($id))
            throw new InvalidArgumentException('Invalid outside lending custody reference.');
    }
    private function actorHall(User $actor,string $companyId,string $hallId,bool $owner):void {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->lockForUpdate()->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !($owner?$this->access->canConfigureHall($actor,$hall):
                $this->access->canManageHallInventory($actor,$hall)))
            throw new AuthorizationException('Current role and exact source hall required for outside lending.');
    }
    private function file(UploadedFile $file):array {
        $path=$file->getRealPath();$size=$file->getSize();
        if(!$file->isValid()||!$path||!is_file($path)||!is_int($size)||
           $size<1||$size>5242880||
           !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/png','image/jpeg'],true))
            throw new InvalidArgumentException('Outside partner signed handover PDF/photo required (max 5 MB).');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Signed partner evidence unreadable.');
        return [$bytes,$size,hash('sha256',$bytes)];
    }
    private function verify(object $record):void {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$record->evidence_storage_path))
            throw new ConflictHttpException('Outside partner signed custody evidence missing.');
        $bytes=$disk->get((string)$record->evidence_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$record->evidence_size||
           !hash_equals((string)$record->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Outside partner signed custody evidence was changed.');
    }
    public function record(User $witness,string $companyId,string $hallId,string $loanId,
        string $handoverId,UploadedFile $evidence,array $physicalCounts):string {
        $this->gate();$this->identifiers($companyId,$hallId,$loanId,$handoverId);
        [$bytes,$size,$hash]=$this->file($evidence);
        $key='private/hall-external-out/'.$companyId.'/'.$loanId.'/'.$handoverId;
        $storage=Storage::disk('local');
        return DB::transaction(function()use($witness,$companyId,$hallId,$loanId,
            $handoverId,$physicalCounts,$bytes,$size,$hash,$key,$storage):string {
            $loan=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_out'||
                (string)$loan->lending_hall_id!==$hallId)
                throw new ConflictHttpException('Outside lending draft and source hall differ.');
            $this->actorHall($witness,$companyId,$hallId,false);
            if((int)$loan->created_by===(int)$witness->getKey())
                throw new AuthorizationException('Draft creator cannot witness outside lending handover.');
            $lines=DB::table('hall_inventory_loan_draft_lines')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->orderBy('item_id')->lockForUpdate()->get();
            if($lines->isEmpty()||$lines->count()>100||$lines->count()!==count($physicalCounts))
                throw new ConflictHttpException('Every drafted item must be separately physically counted.');
            $counts=[];
            foreach($lines as $line){
                $id=(string)$line->item_id;
                if($line->catalog_hall_id!==$hallId||
                    !array_key_exists($id,$physicalCounts)||!is_int($physicalCounts[$id])||
                    $physicalCounts[$id]<0||$physicalCounts[$id]>100000000)
                    throw new InvalidArgumentException('Invalid physically counted outside lending item.');
                $counts[$id]=$physicalCounts[$id];
            }
            ksort($counts,SORT_STRING);
            $old=DB::table('hall_inventory_external_out_handovers')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old){
                $this->verify($old);
                $oldCounts=DB::table('hall_inventory_external_out_handover_lines')
                    ->where('company_id',$companyId)->where('handover_id',$old->id)
                    ->pluck('counted_quantity','item_id')->map(fn($x)=>(int)$x)->all();
                ksort($oldCounts,SORT_STRING);
                if((string)$old->id!==$handoverId||
                   (int)$old->witnessed_by!==(int)$witness->getKey()||
                   (int)$old->evidence_size!==$size||
                   !hash_equals((string)$old->evidence_sha256,$hash)||
                   $oldCounts!==$counts)
                    throw new ConflictHttpException('Conflicting immutable outside lending handover replay.');
                return $handoverId;
            }
            if(!$storage->put($key,$bytes))
                throw new ConflictHttpException('Private external lending evidence storage failed.');
            try {
                DB::table('hall_inventory_external_out_handovers')->insert([
                    'id'=>$handoverId,'company_id'=>$companyId,'loan_id'=>$loanId,
                    'hall_id'=>$hallId,'evidence_sha256'=>$hash,'evidence_size'=>$size,
                    'evidence_storage_path'=>$key,'witnessed_by'=>$witness->getKey()
                ]);
                foreach($counts as $itemId=>$qty)DB::table('hall_inventory_external_out_handover_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'handover_id'=>$handoverId,'loan_id'=>$loanId,'hall_id'=>$hallId,
                    'item_id'=>$itemId,'counted_quantity'=>$qty
                ]);
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$witness->getKey(),
                    'action'=>'hall_inventory.external_out_physical_handover_recorded',
                    'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
                    'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['handoverId'=>$handoverId,'counts'=>$counts,
                        'stockPosted'=>false,'financialPosting'=>false],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['hallId'=>$hallId,'evidenceSha256'=>$hash],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e){$storage->delete($key);throw $e;}
            return $handoverId;
        },3);
    }
    public function approve(User $owner,string $companyId,string $hallId,
        string $loanId,string $dispatchId):string {
        $this->gate();$this->identifiers($companyId,$hallId,$loanId,$dispatchId);
        return DB::transaction(function()use($owner,$companyId,$hallId,$loanId,$dispatchId):string{
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_out'||
                (string)$loan->lending_hall_id!==$hallId)
                throw new ConflictHttpException('External owned lending approval must bind original source hall.');
            $this->actorHall($owner,$companyId,$hallId,true);
            $hand=DB::table('hall_inventory_external_out_handovers')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('hall_id',$hallId)->lockForUpdate()->first();
            if(!$hand)throw new ConflictHttpException('No independent witnessed outside partner handover exists.');
            if((int)$loan->created_by===(int)$owner->getKey()||
               (int)$hand->witnessed_by===(int)$owner->getKey())
                throw new AuthorizationException('Outside lending approval requires three separate staff roles.');
            $this->verify($hand);
            $old=DB::table('hall_inventory_external_out_dispatches')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old){
                if((string)$old->id!==$dispatchId||
                    (int)$old->approved_by!==(int)$owner->getKey())
                    throw new ConflictHttpException('Outside loan already approved; cannot reapprove or post stock.');
                return $dispatchId;
            }
            $lines=DB::table('hall_inventory_loan_draft_lines')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->orderBy('item_id')->lockForUpdate()->get();
            $counts=DB::table('hall_inventory_external_out_handover_lines')
                ->where('company_id',$companyId)->where('handover_id',$hand->id)
                ->where('hall_id',$hallId)->get(['item_id','counted_quantity'])->keyBy('item_id');
            if($lines->isEmpty()||$lines->count()!==$counts->count())
                throw new ConflictHttpException('Outside lending original and physical count lines differ.');
            $positions=[];
            foreach($lines as $line){
                $id=(string)$line->item_id;
                if($line->catalog_hall_id!==$hallId||!$counts->has($id)||
                    (int)$counts[$id]->counted_quantity!==(int)$line->requested_quantity)
                    throw new ConflictHttpException('Outside lending handover count mismatch; investigate before stock posting.');
                $pos=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                    ->where('hall_id',$hallId)->where('item_id',$id)->lockForUpdate()->first();
                if(!$pos||(int)$pos->owned_usable<(int)$line->requested_quantity)
                    throw new ConflictHttpException('Insufficient owned goods for outside physical handover.');
                $positions[$id]=$pos;
            }
            DB::table('hall_inventory_external_out_dispatches')->insert([
                'id'=>$dispatchId,'company_id'=>$companyId,'loan_id'=>$loanId,
                'hall_id'=>$hallId,'handover_id'=>$hand->id,'approved_by'=>$owner->getKey()
            ]);
            foreach($lines as $line){
                $id=(string)$line->item_id;$p=$positions[$id];$qty=(int)$line->requested_quantity;
                $before=['ownedUsable'=>(int)$p->owned_usable,
                    'ownedOnLoan'=>(int)$p->owned_on_loan,
                    'ownedDamaged'=>(int)$p->owned_damaged,
                    'borrowedUsable'=>(int)$p->borrowed_usable,
                    'borrowedTransit'=>(int)$p->borrowed_transit,
                    'borrowedDamaged'=>(int)$p->borrowed_damaged];
                $after=$this->transition->apply($before,'dispatch_owned_loan',$qty);
                $rev=(int)$p->revision;
                $n=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                    ->where('hall_id',$hallId)->where('item_id',$id)
                    ->where('id',$p->id)->where('revision',$rev)->update([
                        'owned_usable'=>$after['ownedUsable'],
                        'owned_on_loan'=>$after['ownedOnLoan'],
                        'revision'=>$rev+1,'updated_at'=>now()
                    ]);
                if($n!==1)throw new ConflictHttpException('Concurrent outside lending balance update rejected.');
                $movement=(string)Str::uuid();
                DB::table('hall_inventory_movement_events')->insert([
                    'id'=>$movement,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'item_id'=>$id,'position_id'=>$p->id,'event_kind'=>'dispatch_owned_loan',
                    'quantity'=>$qty,'revision_before'=>$rev,'revision_after'=>$rev+1,
                    'request_fingerprint'=>hash('sha256',$loanId.$id.$hand->evidence_sha256),
                    'reason'=>'Independently approved outside partner lending '.$loanId,
                    'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                    'actor_id'=>$owner->getKey()
                ]);
                DB::table('hall_inventory_external_out_dispatch_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'dispatch_id'=>$dispatchId,'hall_id'=>$hallId,'item_id'=>$id,
                    'movement_id'=>$movement,'quantity'=>$qty
                ]);
            }
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$owner->getKey(),
                'action'=>'hall_inventory.external_out_owned_stock_dispatched',
                'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
                'before_values'=>json_encode(['state'=>'PHYSICAL_EVIDENCE_ONLY'],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['state'=>'OWNED_ON_LOAN',
                    'dispatchId'=>$dispatchId,'lineCount'=>$lines->count(),
                    'financialPosting'=>false,'loanClosed'=>false],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['hallId'=>$hallId,'handoverId'=>$hand->id,
                    'evidenceSha256'=>$hand->evidence_sha256],JSON_THROW_ON_ERROR)
            ]);
            return $dispatchId;
        },3);
    }
}