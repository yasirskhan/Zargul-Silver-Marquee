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
 * External partner loan returns: partial witnessed dispatch -> borrowed transit,
 * then an independent owner/admin verifies partner-signed acknowledgment before
 * removing transit. Neither stage posts owned stock, liability, charges or cash.
 */
final class HallInventoryExternalBorrowedReturnService {
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $transition) {}
    private function gate():void {
        if(!config('inventory_controls.loan_drafts_enabled')||
            !config('inventory_controls.external_borrowed_intake_enabled')||
            !config('inventory_controls.external_borrowed_return_enabled'))
            throw new ConflictHttpException('External borrowed returns are not released.');
    }
    private function evidence(UploadedFile $file):array {
        $path=$file->getRealPath(); $size=$file->getSize();
        if(!$file->isValid()||!$path||!is_file($path)||!is_int($size)||$size<1||$size>5242880||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/jpeg','image/png'],true))
            throw new InvalidArgumentException('Partner-signed physical return evidence must be PDF/photo, 5 MB maximum.');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Physical return evidence could not be read.');
        return [$bytes,$size,hash('sha256',$bytes)];
    }
    private function verifiedFile(object $row):void {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->evidence_storage_path))
            throw new ConflictHttpException('Signed source evidence is missing.');
        $bytes=$disk->get((string)$row->evidence_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
            !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Historical signed evidence failed integrity verification.');
    }
    private function state(object $p):array {
        return ['ownedUsable'=>(int)$p->owned_usable,'ownedOnLoan'=>(int)$p->owned_on_loan,
            'ownedDamaged'=>(int)$p->owned_damaged,'borrowedUsable'=>(int)$p->borrowed_usable,
            'borrowedTransit'=>(int)$p->borrowed_transit,'borrowedDamaged'=>(int)$p->borrowed_damaged];
    }
    private function movement(User $actor,string $companyId,string $hallId,string $itemId,
        object $position,string $kind,int $qty,string $reference,string $sha):string {
        $before=$this->state($position);
        $after=$this->transition->apply($before,$kind,$qty);
        $rev=(int)$position->revision;
        $updated=DB::table('hall_inventory_positions')->where('company_id',$companyId)
            ->where('hall_id',$hallId)->where('item_id',$itemId)->where('id',$position->id)
            ->where('revision',$rev)->update([
                'borrowed_usable'=>$after['borrowedUsable'],
                'borrowed_transit'=>$after['borrowedTransit'],
                'revision'=>$rev+1,'updated_at'=>now()
            ]);
        if($updated!==1)throw new ConflictHttpException('Concurrent external return position revision changed.');
        $id=(string)Str::uuid();
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>$id,'company_id'=>$companyId,'hall_id'=>$hallId,'item_id'=>$itemId,
            'position_id'=>$position->id,'event_kind'=>$kind,'quantity'=>$qty,
            'revision_before'=>$rev,'revision_after'=>$rev+1,
            'request_fingerprint'=>hash('sha256',$reference.$itemId.$kind.$sha),
            'reason'=>'Independently evidenced partner return '.$reference,
            'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
            'actor_id'=>$actor->getKey()
        ]);
        return $id;
    }
    /** @param array<string,int> $quantities catalog item IDs to physically return */
    public function dispatch(User $actor,string $companyId,string $hallId,string $loanId,
        string $batchId,array $quantities,UploadedFile $file):string {
        $this->gate();
        foreach([$companyId,$hallId,$loanId,$batchId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Invalid external return reference.');
        if(count($quantities)<1||count($quantities)>100)
            throw new InvalidArgumentException('Select one or more item quantities for return.');
        foreach($quantities as $id=>$qty)
            if(!is_string($id)||!Str::isUuid($id)||!is_int($qty)||$qty<1||$qty>100000000)
                throw new InvalidArgumentException('Return items require UUIDs and positive whole quantities.');
        ksort($quantities,SORT_STRING);
        [$bytes,$size,$sha]=$this->evidence($file);
        $disk=Storage::disk('local');
        $key='private/hall-external-return-dispatch/'.$companyId.'/'.$loanId.'/'.$batchId;
        return DB::transaction(function()use($actor,$companyId,$hallId,$loanId,$batchId,
            $quantities,$bytes,$size,$sha,$disk,$key):string {
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Outside return must originate from its exact borrowing hall.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canManageHallInventory($actor,$hall)||
                (int)$loan->created_by===(int)$actor->getKey())
                throw new AuthorizationException('Independent current borrowing-hall inventory operator required.');
            $accept=DB::table('hall_inventory_external_in_acceptances')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('hall_id',$hallId)->first();
            $receipt=DB::table('hall_inventory_external_in_receipts')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('hall_id',$hallId)->first();
            if(!$accept||!$receipt)
                throw new ConflictHttpException('Outside borrowed stock was not independently accepted.');
            $this->verifiedFile($receipt);
            $old=DB::table('hall_inventory_external_in_return_batches')
                ->where('company_id',$companyId)->where('id',$batchId)->first();
            if($old) {
                if($old->loan_id!==$loanId||$old->hall_id!==$hallId||
                    (int)$old->dispatched_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->evidence_sha256,$sha)||
                    (int)$old->evidence_size!==$size)
                    throw new ConflictHttpException('Return batch key conflicts with its original custody.');
                $this->verifiedFile($old);
                $existing=DB::table('hall_inventory_external_in_return_lines')
                    ->where('company_id',$companyId)->where('batch_id',$batchId)
                    ->pluck('quantity','item_id')->map(static fn($n):int=>(int)$n)->all();
                ksort($existing,SORT_STRING);
                if($existing!==$quantities)
                    throw new ConflictHttpException('Physical return replay changes original quantities.');
                return $batchId;
            }
            $accepted=DB::table('hall_inventory_external_in_acceptance_lines')
                ->where('company_id',$companyId)->where('acceptance_id',$accept->id)
                ->where('hall_id',$hallId)->whereIn('item_id',array_keys($quantities))
                ->orderBy('item_id')->get(['item_id','quantity'])->keyBy('item_id');
            if($accepted->count()!==count($quantities))
                throw new ConflictHttpException('Return item is not on the independently accepted outside loan.');
            $positions=[];
            foreach($quantities as $itemId=>$qty) {
                $prior=(int)DB::table('hall_inventory_external_in_return_lines as l')
                    ->join('hall_inventory_external_in_return_batches as b','b.id','=','l.batch_id')
                    ->where('b.company_id',$companyId)->where('b.loan_id',$loanId)
                    ->where('l.company_id',$companyId)->where('l.item_id',$itemId)->sum('l.quantity');
                if($prior+$qty>(int)$accepted[$itemId]->quantity)
                    throw new ConflictHttpException('Partial returns exceed original outside borrowed quantity.');
                $p=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                    ->where('hall_id',$hallId)->where('item_id',$itemId)->lockForUpdate()->first();
                if(!$p||(int)$p->borrowed_usable<$qty)
                    throw new ConflictHttpException('Insufficient available borrowed stock for this return.');
                $positions[$itemId]=$p;
            }
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Signed outside return handover evidence could not be stored.');
            try {
                DB::table('hall_inventory_external_in_return_batches')->insert([
                    'id'=>$batchId,'company_id'=>$companyId,'loan_id'=>$loanId,'hall_id'=>$hallId,
                    'evidence_sha256'=>$sha,'evidence_size'=>$size,'evidence_storage_path'=>$key,
                    'dispatched_by'=>$actor->getKey()
                ]);
                foreach($quantities as $itemId=>$qty) {
                    $movement=$this->movement($actor,$companyId,$hallId,$itemId,$positions[$itemId],
                        'send_borrowed_back',$qty,$batchId,$sha);
                    DB::table('hall_inventory_external_in_return_lines')->insert([
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,'hall_id'=>$hallId,
                        'batch_id'=>$batchId,'item_id'=>$itemId,'movement_id'=>$movement,'quantity'=>$qty
                    ]);
                }
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                    'action'=>'hall_inventory.external_borrowed_partial_return_dispatched',
                    'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
                    'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['batchId'=>$batchId,'physicalQuantities'=>$quantities,
                        'status'=>'PARTNER_RECEIPT_PENDING','financialPosting'=>false],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['hallId'=>$hallId,'evidenceSha256'=>$sha],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e){$disk->delete($key);throw $e;}
            return $batchId;
        },3);
    }
    /** Full batch confirmation only. Any physical discrepancy must be investigated separately. */
    public function confirm(User $actor,string $companyId,string $hallId,string $batchId,
        string $confirmationId,array $physicalCountByItem,UploadedFile $file):string {
        $this->gate();
        foreach([$companyId,$hallId,$batchId,$confirmationId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Invalid external return confirmation reference.');
        if(count($physicalCountByItem)<1||count($physicalCountByItem)>100)
            throw new InvalidArgumentException('Independently checked partner counts are required.');
        foreach($physicalCountByItem as $id=>$qty)
            if(!is_string($id)||!Str::isUuid($id)||!is_int($qty)||$qty<0||$qty>100000000)
                throw new InvalidArgumentException('Partner receipt counts must be nonnegative whole units.');
        ksort($physicalCountByItem,SORT_STRING);
        [$bytes,$size,$sha]=$this->evidence($file);
        $disk=Storage::disk('local');
        $key='private/hall-external-return-confirmation/'.$companyId.'/'.$batchId.'/'.$confirmationId;
        return DB::transaction(function()use($actor,$companyId,$hallId,$batchId,$confirmationId,
            $physicalCountByItem,$bytes,$size,$sha,$disk,$key):string {
            $batch=DB::table('hall_inventory_external_in_return_batches')
                ->where('company_id',$companyId)->where('id',$batchId)->first();
            if(!$batch||$batch->hall_id!==$hallId)
                throw new ConflictHttpException('Partner physical return batch missing or wrong hall.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$batch->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Partner return no longer belongs to original borrowing hall.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall)||
                (int)$batch->dispatched_by===(int)$actor->getKey()||
                (int)$loan->created_by===(int)$actor->getKey())
                throw new AuthorizationException('Different current owner/admin must verify partner receipt.');
            $this->verifiedFile($batch);
            $old=DB::table('hall_inventory_external_in_return_confirmations')
                ->where('company_id',$companyId)->where('batch_id',$batchId)->first();
            if($old) {
                if($old->id!==$confirmationId||(int)$old->confirmed_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->evidence_sha256,$sha)||(int)$old->evidence_size!==$size)
                    throw new ConflictHttpException('Partner return already accepted with different evidence.');
                $this->verifiedFile($old);
                $oldQty=DB::table('hall_inventory_external_in_return_confirmation_lines')
                    ->where('company_id',$companyId)->where('confirmation_id',$confirmationId)
                    ->pluck('quantity','item_id')->map(static fn($n):int=>(int)$n)->all();
                ksort($oldQty,SORT_STRING);
                if($oldQty!==$physicalCountByItem)
                    throw new ConflictHttpException('Original partner-received counts cannot change.');
                return $confirmationId;
            }
            $lines=DB::table('hall_inventory_external_in_return_lines')
                ->where('company_id',$companyId)->where('batch_id',$batchId)
                ->orderBy('item_id')->get(['item_id','quantity']);
            if($lines->isEmpty()||$lines->count()!==count($physicalCountByItem))
                throw new ConflictHttpException('Every physically shipped item needs independent partner count.');
            $positions=[];
            foreach($lines as $line) {
                $id=(string)$line->item_id;$qty=(int)$line->quantity;
                if(!array_key_exists($id,$physicalCountByItem)||$physicalCountByItem[$id]!==$qty)
                    throw new ConflictHttpException('Partner count is short, damaged or disputed; cannot silently clear transit.');
                $p=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                    ->where('hall_id',$hallId)->where('item_id',$id)->lockForUpdate()->first();
                if(!$p||(int)$p->borrowed_transit<$qty)
                    throw new ConflictHttpException('Partner return transit position cannot be independently reconciled.');
                $positions[$id]=$p;
            }
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Partner signed return acknowledgment could not be stored.');
            try {
                DB::table('hall_inventory_external_in_return_confirmations')->insert([
                    'id'=>$confirmationId,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'batch_id'=>$batchId,'evidence_sha256'=>$sha,'evidence_size'=>$size,
                    'evidence_storage_path'=>$key,'confirmed_by'=>$actor->getKey()
                ]);
                foreach($lines as $line) {
                    $id=(string)$line->item_id;$qty=(int)$line->quantity;
                    $movement=$this->movement($actor,$companyId,$hallId,$id,$positions[$id],
                        'confirm_borrowed_return',$qty,$confirmationId,$sha);
                    DB::table('hall_inventory_external_in_return_confirmation_lines')->insert([
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,'hall_id'=>$hallId,
                        'confirmation_id'=>$confirmationId,'item_id'=>$id,
                        'movement_id'=>$movement,'quantity'=>$qty
                    ]);
                }
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                    'action'=>'hall_inventory.external_borrowed_partner_return_confirmed',
                    'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loan->id,
                    'before_values'=>json_encode(['batchId'=>$batchId,'status'=>'IN_TRANSIT'],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['confirmationId'=>$confirmationId,
                        'partnerReceivedCounts'=>$physicalCountByItem,
                        'status'=>'PARTNER_PHYSICAL_RECEIPT_CONFIRMED',
                        'financialPosting'=>false],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['hallId'=>$hallId,'partnerEvidenceSha256'=>$sha],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e){$disk->delete($key);throw $e;}
            return $confirmationId;
        },3);
    }
    /** Read-only batch history for an active hall operator. */
    public function recent(User $actor,string $companyId,string $hallId):array {
        $this->gate();
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('External return custody history is hall-restricted.');
        $batches=DB::table('hall_inventory_external_in_return_batches as b')
            ->leftJoin('hall_inventory_external_in_return_confirmations as c',function($j):void {
                $j->on('c.company_id','=','b.company_id')->on('c.batch_id','=','b.id');
            })
            ->where('b.company_id',$companyId)->where('b.hall_id',$hallId)
            ->orderByDesc('b.created_at')->orderByDesc('b.id')->limit(40)
            ->get(['b.id','b.loan_id','b.dispatched_by','b.created_at','c.id as confirmation_id']);
        if($batches->isEmpty())return [];
        $lines=DB::table('hall_inventory_external_in_return_lines')
            ->where('company_id',$companyId)->whereIn('batch_id',$batches->pluck('id')->all())
            ->orderBy('item_id')->get(['batch_id','item_id','quantity'])->groupBy('batch_id');
        return $batches->map(static fn($b):array=>[
            'batchId'=>(string)$b->id,'loanId'=>(string)$b->loan_id,
            'dispatchedBy'=>(int)$b->dispatched_by,
            'confirmed'=>$b->confirmation_id!==null,
            'createdAt'=>(string)$b->created_at,
            'lines'=>($lines->get($b->id)??collect())->map(static fn($l):array=>[
                'itemId'=>(string)$l->item_id,'quantity'=>(int)$l->quantity
            ])->all()
        ])->all();
    }
}
