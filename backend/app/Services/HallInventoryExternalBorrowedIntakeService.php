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
 * Partner-to-hall external borrowing: an independently witnessed signed physical
 * receipt stays EVIDENCE ONLY until another owner/admin accepts it. The
 * acceptance credits borrowed custody, never owned stock, cash or liability.
 */
final class HallInventoryExternalBorrowedIntakeService {
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $transition) {}
    private function gated():void {
        if(!config('inventory_controls.loan_drafts_enabled') ||
            !config('inventory_controls.external_borrowed_intake_enabled'))
            throw new ConflictHttpException('External borrowed arrival is not released.');
    }
    public function record(User $counter,string $companyId,string $hallId,
        string $loanId,string $receiptId,UploadedFile $evidence,array $countedByItem):string {
        $this->gated();
        foreach([$companyId,$hallId,$loanId,$receiptId] as $x)
            if(!Str::isUuid($x))throw new InvalidArgumentException('Invalid external arrival reference.');
        $path=$evidence->getRealPath();$size=$evidence->getSize();
        if(!$evidence->isValid()||!$path||!is_file($path)||!is_int($size)||
            $size<1||$size>5242880||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/jpeg','image/png'],true))
            throw new InvalidArgumentException('Signed external handover evidence (PDF or photo) required.');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Physical delivery evidence unreadable.');
        $sha=hash('sha256',$bytes);
        $key='private/hall-external-borrowed-receipt/'.$companyId.'/'.$loanId.'/'.$receiptId;
        $disk=Storage::disk('local');
        return DB::transaction(function()use($counter,$companyId,$hallId,$loanId,$receiptId,
            $size,$sha,$bytes,$key,$disk,$countedByItem):string {
            $loan=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('This draft does not borrow from an outside partner into this hall.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$counter->getKey()||
                !$this->access->canManageHallInventory($counter,$hall))
                throw new AuthorizationException('Current assigned receiving-hall staff required.');
            if((int)$loan->created_by===(int)$counter->getKey())
                throw new AuthorizationException('Draft creator cannot independently count outside delivery.');
            $draftLines=DB::table('hall_inventory_loan_draft_lines')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->orderBy('item_id')->lockForUpdate()->get();
            if($draftLines->isEmpty() || $draftLines->count()>100 ||
                $draftLines->count()!==count($countedByItem))
                throw new ConflictHttpException('Every physical item requires an independently entered count.');
            $normalized=[];
            foreach($draftLines as $draftLine) {
                $itemId=(string)$draftLine->item_id;
                if((string)$draftLine->catalog_hall_id!==$hallId ||
                    !array_key_exists($itemId,$countedByItem) ||
                    !is_int($countedByItem[$itemId]) ||
                    $countedByItem[$itemId]<0 ||
                    $countedByItem[$itemId]>100000000)
                    throw new InvalidArgumentException('Invalid separately counted outside delivery item.');
                $normalized[$itemId]=$countedByItem[$itemId];
            }
            ksort($normalized,SORT_STRING);
            $old=DB::table('hall_inventory_external_in_receipts')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old) {
                if((string)$old->id!==$receiptId||
                    (int)$old->received_by!==(int)$counter->getKey()||
                    !hash_equals((string)$old->evidence_sha256,$sha)||
                    (int)$old->evidence_size!==$size||
                    !$disk->exists((string)$old->evidence_storage_path)||
                    !hash_equals($sha,hash('sha256',(string)$disk->get((string)$old->evidence_storage_path))))
                    throw new ConflictHttpException('Outside borrowing receipt already filed or evidence changed.');
                $existingCounts=DB::table('hall_inventory_external_in_receipt_lines')
                    ->where('company_id',$companyId)->where('receipt_id',$receiptId)
                    ->pluck('counted_quantity','item_id')
                    ->map(static fn($n):int=>(int)$n)->all();
                ksort($existingCounts,SORT_STRING);
                if($existingCounts!==$normalized)
                    throw new ConflictHttpException('Physical receipt replay changes original counted quantities.');
                return $receiptId;
            }
            $lines=$draftLines;
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Signed outside handover could not be retained privately.');
            try {
                DB::table('hall_inventory_external_in_receipts')->insert([
                    'id'=>$receiptId,'company_id'=>$companyId,'loan_id'=>$loanId,
                    'hall_id'=>$hallId,'evidence_sha256'=>$sha,
                    'evidence_size'=>$size,'evidence_storage_path'=>$key,
                    'received_by'=>$counter->getKey()
                ]);
                $countRows=[];
                foreach($lines as $line) {
                    $itemId=(string)$line->item_id;
                    $countRows[]=[
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                        'loan_id'=>$loanId,'hall_id'=>$hallId,'receipt_id'=>$receiptId,
                        'item_id'=>$itemId,'counted_quantity'=>$normalized[$itemId]
                    ];
                }
                DB::table('hall_inventory_external_in_receipt_lines')->insert($countRows);
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'actor_id'=>$counter->getKey(),
                    'action'=>'hall_inventory.external_borrowed_physical_receipt_submitted',
                    'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
                    'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['receiptId'=>$receiptId,'lineCount'=>$lines->count(),
                        'physicalCountByItem'=>$normalized,'stockPosted'=>false,'financialPosting'=>false],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['hallId'=>$hallId,'sha256'=>$sha],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e){$disk->delete($key);throw $e;}
            return $receiptId;
        },3);
    }
    public function accept(User $approver,string $companyId,string $hallId,
        string $loanId,string $acceptanceId):string {
        $this->gated();
        foreach([$companyId,$hallId,$loanId,$acceptanceId] as $x)
            if(!Str::isUuid($x))throw new InvalidArgumentException('Invalid independent external acceptance key.');
        return DB::transaction(function()use($approver,$companyId,$hallId,$loanId,$acceptanceId):string {
            $loan=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Outside borrower receipt does not match this hall.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$approver->getKey()||
                !$this->access->canConfigureHall($approver,$hall))
                throw new AuthorizationException('Current owner/admin independent approval required.');
            $receipt=DB::table('hall_inventory_external_in_receipts')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('hall_id',$hallId)->lockForUpdate()->first();
            if(!$receipt)throw new ConflictHttpException('Signed external physical handover is not recorded.');
            if((int)$loan->created_by===(int)$approver->getKey()||
                (int)$receipt->received_by===(int)$approver->getKey())
                throw new AuthorizationException('Independent owner must differ from drafter and physical counter.');
            $disk=Storage::disk('local');
            if(!$disk->exists((string)$receipt->evidence_storage_path))
                throw new ConflictHttpException('Source-signed handover evidence is missing.');
            $bytes=$disk->get((string)$receipt->evidence_storage_path);
            if(!is_string($bytes)||strlen($bytes)!==(int)$receipt->evidence_size||
                !hash_equals((string)$receipt->evidence_sha256,hash('sha256',$bytes)))
                throw new ConflictHttpException('Outside partner signed receipt integrity failed.');
            $old=DB::table('hall_inventory_external_in_acceptances')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($old) {
                if((string)$old->id!==$acceptanceId ||
                    (int)$old->approved_by!==(int)$approver->getKey())
                    throw new ConflictHttpException('Borrowed intake previously accepted by another decision.');
                return $acceptanceId;
            }
            $lines=DB::table('hall_inventory_loan_draft_lines')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->orderBy('item_id')->lockForUpdate()->get();
            if($lines->isEmpty()||$lines->count()>100)
                throw new ConflictHttpException('No valid signed borrowing request lines.');
            $counts=DB::table('hall_inventory_external_in_receipt_lines')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('loan_id',$loanId)->where('receipt_id',$receipt->id)
                ->get(['item_id','counted_quantity'])->keyBy('item_id');
            if($counts->count()!==$lines->count())
                throw new ConflictHttpException('External physical count line evidence is incomplete.');
            foreach($lines as $line) {
                $count=$counts->get($line->item_id);
                if(!$count || (int)$count->counted_quantity!==(int)$line->requested_quantity)
                    throw new ConflictHttpException('Physical quantity does not match planned outside loan; separately resolve discrepancy.');
            }
            $items=$lines->pluck('item_id')->all();
            $catalog=DB::table('hall_inventory_items')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('is_active',true)->whereIn('id',$items)
                ->orderBy('id')->lockForUpdate()->get(['id','unit'])->keyBy('id');
            if($catalog->count()!==$lines->count())
                throw new ConflictHttpException('External intake references an invalid hall catalog.');
            $positions=[];
            foreach($lines as $line) {
                $id=(string)$line->item_id;
                if($line->catalog_hall_id!==$hallId||!$catalog->has($id)||
                    $catalog[$id]->unit!==$line->unit_snapshot)
                    throw new ConflictHttpException('Borrowed item unit/hall snapshot changed.');
                $positions[$id]=DB::table('hall_inventory_positions')
                    ->where('company_id',$companyId)->where('hall_id',$hallId)
                    ->where('item_id',$id)->lockForUpdate()->first();
            }
            DB::table('hall_inventory_external_in_acceptances')->insert([
                'id'=>$acceptanceId,'company_id'=>$companyId,'loan_id'=>$loanId,
                'hall_id'=>$hallId,'receipt_id'=>$receipt->id,
                'approved_by'=>$approver->getKey()
            ]);
            foreach($lines as $line) {
                $id=(string)$line->item_id;$pos=$positions[$id];$qty=(int)$line->requested_quantity;
                $before=['ownedUsable'=>$pos?(int)$pos->owned_usable:0,
                    'ownedOnLoan'=>$pos?(int)$pos->owned_on_loan:0,
                    'ownedDamaged'=>$pos?(int)$pos->owned_damaged:0,
                    'borrowedUsable'=>$pos?(int)$pos->borrowed_usable:0,
                    'borrowedTransit'=>$pos?(int)$pos->borrowed_transit:0,
                    'borrowedDamaged'=>$pos?(int)$pos->borrowed_damaged:0];
                $rev=$pos?(int)$pos->revision:0;
                $positionId=$pos?(string)$pos->id:(string)Str::uuid();
                if(!$pos)DB::table('hall_inventory_positions')->insert([
                    'id'=>$positionId,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'item_id'=>$id,'created_at'=>now(),'updated_at'=>now()
                ]);
                $after=$this->transition->apply($before,'accept_borrowed',$qty);
                $changed=DB::table('hall_inventory_positions')->where('id',$positionId)
                    ->where('company_id',$companyId)->where('hall_id',$hallId)
                    ->where('item_id',$id)->where('revision',$rev)->update([
                    'borrowed_usable'=>$after['borrowedUsable'],'revision'=>$rev+1,
                    'updated_at'=>now()
                ]);
                if($changed!==1)throw new ConflictHttpException('Outside intake position revision conflict.');
                $movement=(string)Str::uuid();
                DB::table('hall_inventory_movement_events')->insert([
                    'id'=>$movement,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'item_id'=>$id,'position_id'=>$positionId,'event_kind'=>'accept_borrowed',
                    'quantity'=>$qty,'revision_before'=>$rev,'revision_after'=>$rev+1,
                    'request_fingerprint'=>hash('sha256',$loanId.$id.$receipt->evidence_sha256),
                    'reason'=>'Independently accepted external borrowed arrival '.$loanId,
                    'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                    'actor_id'=>$approver->getKey()
                ]);
                DB::table('hall_inventory_external_in_acceptance_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'acceptance_id'=>$acceptanceId,'hall_id'=>$hallId,
                    'item_id'=>$id,'movement_id'=>$movement,'quantity'=>$qty
                ]);
            }
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$approver->getKey(),
                'action'=>'hall_inventory.external_borrowed_stock_accepted',
                'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
                'before_values'=>json_encode(['status'=>'EVIDENCE_ONLY'],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['status'=>'BORROWED_IN_CUSTODY',
                    'acceptanceId'=>$acceptanceId,'lineCount'=>$lines->count(),
                    'ownedStockChanged'=>false,'financialPosting'=>false],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['hallId'=>$hallId,'receiptId'=>$receipt->id,
                    'signedEvidenceSha256'=>$receipt->evidence_sha256],JSON_THROW_ON_ERROR)
            ]);
            return $acceptanceId;
        },3);
    }
}
