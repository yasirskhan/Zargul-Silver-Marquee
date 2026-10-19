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
 * Independently witnesses full INTERNAL destination receipt.
 * Source owned_on_loan stays outstanding until a separate return workflow.
 * Only destination borrowed_usable increases; no money or closure is posted.
 */
final class HallInventoryInternalLoanReceiptService
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $positions
    ) {}

    /**
     * @param array<string,string> $destItemBySource keyed by lending catalog UUID
     * @param array<string,int> $expectedRevisions keyed by destination catalog UUID
     */
    public function receive(User $actor,string $companyId,string $loanId,
        string $receiptId,array $destItemBySource,array $expectedRevisions,
        UploadedFile $evidence):string
    {
        if (!config('inventory_controls.loan_drafts_enabled') ||
            !config('inventory_controls.loan_dispatch_enabled') ||
            !config('inventory_controls.loan_receipt_enabled'))
            throw new ConflictHttpException('Independent loan receiving is disabled.');
        if (!Str::isUuid($companyId)||!Str::isUuid($loanId)||!Str::isUuid($receiptId))
            throw new InvalidArgumentException('Invalid internal loan receipt identifiers.');
        $path=$evidence->getRealPath();
        $size=$evidence->getSize();
        if (!$evidence->isValid()||!$path||!is_file($path)||
            !is_int($size)||$size<1||$size>5242880||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/jpeg','image/png'],true))
            throw new InvalidArgumentException('Authenticated physical receiving attachment required.');
        $bytes=file_get_contents($path);
        if (!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Physical receiving attachment is unreadable.');
        $sha=hash('sha256',$bytes);
        $disk=Storage::disk('local');
        $key='private/hall-loan-receipt/'.$companyId.'/'.$loanId.'/'.$receiptId;

        return DB::transaction(function() use ($actor,$companyId,$loanId,$receiptId,
            $destItemBySource,$expectedRevisions,$bytes,$sha,$size,$disk,$key):string {
            $draft=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$loanId)->lockForUpdate()->first();
            if (!$draft||$draft->direction!=='internal')
                throw new ConflictHttpException('Only an internal draft may be received.');
            $ids=[$draft->lending_hall_id,$draft->borrowing_hall_id];
            sort($ids,SORT_STRING);
            $halls=Hall::query()->where('company_id',$companyId)->whereIn('id',$ids)
                ->where('is_active',true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $dest=$halls->get($draft->borrowing_hall_id);
            if ($halls->count()!==2||!$dest||!Auth::check()||
                (int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canManageHallInventory($actor,$dest))
                throw new AuthorizationException('Only current receiving-hall staff may attest.');
            $dispatch=DB::table('hall_inventory_loan_dispatches')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->lockForUpdate()->first();
            if (!$dispatch||$dispatch->borrowing_hall_id!==$dest->id ||
                $dispatch->lending_hall_id!==$draft->lending_hall_id)
                throw new ConflictHttpException('Matching authorized source dispatch is required.');
            if ((int)$actor->getKey()===(int)$dispatch->dispatched_by ||
                (int)$actor->getKey()===(int)$draft->created_by)
                throw new AuthorizationException('Receiving must be witnessed by a distinct actor.');

            $old=DB::table('hall_inventory_internal_loan_receipts')
                ->where('company_id',$companyId)->where('dispatch_id',$dispatch->id)->first();
            if ($old) {
                if ((string)$old->id!==$receiptId||
                    (int)$old->received_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->evidence_sha256,$sha)||
                    (int)$old->evidence_size!==$size||
                    !$disk->exists((string)$old->evidence_storage_path)||
                    !hash_equals($sha,hash('sha256',$disk->get((string)$old->evidence_storage_path))))
                    throw new ConflictHttpException('Destination already received with different evidence.');
                $oldLines=DB::table('hall_inventory_internal_loan_receipt_lines')
                    ->where('company_id',$companyId)->where('receipt_id',$receiptId)
                    ->pluck('item_id','source_item_id')->all();
                $replayedMapping=$destItemBySource;
                ksort($oldLines,SORT_STRING);
                ksort($replayedMapping,SORT_STRING);
                if ($oldLines!==$replayedMapping)
                    throw new ConflictHttpException('Destination receipt replay mapping changed.');
                return $receiptId;
            }
            $lines=DB::table('hall_inventory_loan_draft_lines as l')
                ->join('hall_inventory_loan_dispatch_lines as dl',function($j)use($dispatch):void {
                    $j->on('dl.company_id','=','l.company_id')
                        ->on('dl.item_id','=','l.item_id')
                        ->where('dl.dispatch_id','=',$dispatch->id);
                })
                ->where('l.company_id',$companyId)->where('l.loan_id',$loanId)
                ->orderBy('l.item_id')
                ->get(['l.item_id','l.item_name_snapshot','l.unit_snapshot',
                    'l.requested_quantity','dl.quantity as dispatched_quantity']);
            if ($lines->isEmpty()||$lines->count()!==count($destItemBySource)||
                $lines->count()!==count($expectedRevisions))
                throw new ConflictHttpException('Every dispatched item requires one mapped destination row.');
            foreach($destItemBySource as $sourceId=>$destinationId) {
                if (!is_string($sourceId)||!Str::isUuid($sourceId)||
                    !is_string($destinationId)||!Str::isUuid($destinationId))
                    throw new InvalidArgumentException('Invalid source/destination catalog mapping.');
            }
            $ids=array_values($destItemBySource);
            if(count(array_unique($ids))!==$lines->count())
                throw new ConflictHttpException('Duplicate receiving catalog item mapping.');
            sort($ids,SORT_STRING);
            $catalog=DB::table('hall_inventory_items')
                ->where('company_id',$companyId)->where('hall_id',$dest->id)
                ->where('is_active',true)->whereIn('id',$ids)
                ->orderBy('id')->lockForUpdate()->get(['id','name','unit'])->keyBy('id');
            if ($catalog->count()!==count($ids))
                throw new AuthorizationException('Mapped items must be in the receiving hall catalog.');
            $positions=[];
            foreach($ids as $id) {
                if (!array_key_exists($id,$expectedRevisions)||
                    !is_int($expectedRevisions[$id])||$expectedRevisions[$id]<0)
                    throw new ConflictHttpException('Expected destination revisions are required.');
                $pos=DB::table('hall_inventory_positions')
                    ->where('company_id',$companyId)->where('hall_id',$dest->id)
                    ->where('item_id',$id)->lockForUpdate()->first();
                if ($pos && (int)$pos->revision!==$expectedRevisions[$id])
                    throw new ConflictHttpException('Destination position revision changed.');
                if (!$pos && $expectedRevisions[$id]!==0)
                    throw new ConflictHttpException('New destination position revision must be zero.');
                $positions[$id]=$pos;
            }
            foreach($lines as $line) {
                $src=(string)$line->item_id;
                $target=$destItemBySource[$src]??null;
                $item=$target?$catalog->get($target):null;
                if (!$item||$line->item_name_snapshot!==$item->name||
                    $line->unit_snapshot!==$item->unit||
                    (int)$line->requested_quantity!==(int)$line->dispatched_quantity)
                    throw new ConflictHttpException('Receipt item, unit or dispatched count mismatch.');
            }
            if (!$disk->exists((string)$dispatch->evidence_storage_path))
                throw new ConflictHttpException('Original source handover evidence is missing.');
            $dispatchBytes=$disk->get((string)$dispatch->evidence_storage_path);
            if (!is_string($dispatchBytes)||
                strlen($dispatchBytes)!==(int)$dispatch->evidence_size||
                !hash_equals((string)$dispatch->evidence_sha256,hash('sha256',$dispatchBytes)))
                throw new ConflictHttpException('Original source handover evidence was altered.');
            if (!$disk->put($key,$bytes))
                throw new ConflictHttpException('Private receiving evidence could not be stored.');
            try {
                DB::table('hall_inventory_internal_loan_receipts')->insert([
                    'id'=>$receiptId,'company_id'=>$companyId,'loan_id'=>$loanId,
                    'dispatch_id'=>$dispatch->id,'receiving_hall_id'=>$dest->id,
                    'evidence_sha256'=>$sha,'evidence_size'=>$size,'evidence_storage_path'=>$key,
                    'received_by'=>$actor->getKey()
                ]);
                foreach($lines as $line) {
                    $target=$destItemBySource[(string)$line->item_id];
                    $pos=$positions[$target];
                    $before=[
                        'ownedUsable'=>$pos?(int)$pos->owned_usable:0,
                        'ownedOnLoan'=>$pos?(int)$pos->owned_on_loan:0,
                        'ownedDamaged'=>$pos?(int)$pos->owned_damaged:0,
                        'borrowedUsable'=>$pos?(int)$pos->borrowed_usable:0,
                        'borrowedTransit'=>$pos?(int)$pos->borrowed_transit:0,
                        'borrowedDamaged'=>$pos?(int)$pos->borrowed_damaged:0
                    ];
                    $revision=$pos?(int)$pos->revision:0;
                    $positionId=$pos?(string)$pos->id:(string)Str::uuid();
                    if (!$pos) {
                        DB::table('hall_inventory_positions')->insert([
                            'id'=>$positionId,'company_id'=>$companyId,
                            'hall_id'=>$dest->id,'item_id'=>$target,
                            'revision'=>0,'created_at'=>now(),'updated_at'=>now()
                        ]);
                    }
                    $qty=(int)$line->dispatched_quantity;
                    $after=$this->positions->apply($before,'accept_borrowed',$qty);
                    $updated=DB::table('hall_inventory_positions')
                        ->where('id',$positionId)->where('company_id',$companyId)
                        ->where('hall_id',$dest->id)->where('item_id',$target)
                        ->where('revision',$revision)->update([
                            'borrowed_usable'=>$after['borrowedUsable'],
                            'revision'=>$revision+1,'updated_at'=>now()
                        ]);
                    if ($updated!==1)throw new ConflictHttpException('Concurrent destination receipt revision conflict.');
                    $movementId=(string)Str::uuid();
                    DB::table('hall_inventory_movement_events')->insert([
                        'id'=>$movementId,'company_id'=>$companyId,
                        'hall_id'=>$dest->id,'item_id'=>$target,'position_id'=>$positionId,
                        'event_kind'=>'accept_borrowed','quantity'=>$qty,
                        'revision_before'=>$revision,'revision_after'=>$revision+1,
                        'request_fingerprint'=>hash('sha256',$receiptId.(string)$line->item_id.$sha),
                        'reason'=>'Independent internal loan arrival '.$loanId,
                        'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                        'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                        'actor_id'=>$actor->getKey()
                    ]);
                    DB::table('hall_inventory_internal_loan_receipt_lines')->insert([
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                        'receipt_id'=>$receiptId,'hall_id'=>$dest->id,
                        'source_item_id'=>$line->item_id,'item_id'=>$target,
                        'movement_id'=>$movementId,'quantity'=>$qty
                    ]);
                }
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'actor_id'=>$actor->getKey(),
                    'action'=>'hall_inventory.internal_loan_destination_received',
                    'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
                    'before_values'=>json_encode(['state'=>'SOURCE_DISPATCH_RECORDED_RECEIPT_PENDING'],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['state'=>'DESTINATION_RECEIVED_RETURN_PENDING',
                        'receiptId'=>$receiptId,'lineCount'=>$lines->count()],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['source'=>$draft->lending_hall_id,
                        'target'=>$dest->id,'receiptAttachmentHash'=>$sha],JSON_THROW_ON_ERROR),
                ]);
            }catch(\Throwable $e) {
                $disk->delete($key);
                throw $e;
            }
            return $receiptId;
        },3);
    }
}
