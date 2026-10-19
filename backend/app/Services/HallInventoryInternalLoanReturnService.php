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
 * Independent receiving-hall partial RETURN DISPATCH only.
 * Borrowed usable becomes borrowed in transit, while the lender's
 * owned-on-loan is NOT reduced until a separate source acceptance.
 */
final class HallInventoryInternalLoanReturnService
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $positions
    ) {}

    /** @param array<string,int> $returnQuantities keyed by original source item UUID
     *  @param array<string,int> $expectedRevisions keyed by destination item UUID
     */
    public function send(User $actor,string $companyId,string $loanId,
        string $batchId,array $returnQuantities,array $expectedRevisions,
        UploadedFile $evidence):string
    {
        if (!config('inventory_controls.loan_drafts_enabled') ||
            !config('inventory_controls.loan_dispatch_enabled') ||
            !config('inventory_controls.loan_receipt_enabled') ||
            !config('inventory_controls.loan_returns_enabled'))
            throw new ConflictHttpException('Internal return dispatch is disabled.');
        if (!Str::isUuid($companyId)||!Str::isUuid($loanId)||!Str::isUuid($batchId)||
            count($returnQuantities)<1||count($returnQuantities)>100)
            throw new InvalidArgumentException('Invalid internal return request.');
        foreach($returnQuantities as $sourceItem=>$qty) {
            if (!is_string($sourceItem)||!Str::isUuid($sourceItem)||
                !is_int($qty)||$qty<1||$qty>100000000)
                throw new InvalidArgumentException('Return quantities must use UUIDs and positive integers.');
        }
        ksort($returnQuantities,SORT_STRING);
        $path=$evidence->getRealPath();
        $size=$evidence->getSize();
        if (!$evidence->isValid()||!$path||!is_file($path)||
            !is_int($size)||$size<1||$size>5242880||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/png','image/jpeg'],true))
            throw new InvalidArgumentException('Bounded return handover photograph or PDF required.');
        $bytes=file_get_contents($path);
        if (!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Physical return evidence could not be read.');
        $sha=hash('sha256',$bytes);
        $disk=Storage::disk('local');
        $key='private/hall-loan-return/'.$companyId.'/'.$loanId.'/'.$batchId;

        return DB::transaction(function()use($actor,$companyId,$loanId,$batchId,
            $returnQuantities,$expectedRevisions,$sha,$size,$bytes,$disk,$key):string {
            $draft=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$loanId)->lockForUpdate()->first();
            if (!$draft||$draft->direction!=='internal')
                throw new ConflictHttpException('Return requires original internal loan.');
            $ids=[$draft->lending_hall_id,$draft->borrowing_hall_id];
            sort($ids,SORT_STRING);
            $halls=Hall::query()->where('company_id',$companyId)->whereIn('id',$ids)
                ->where('is_active',true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $destination=$halls->get($draft->borrowing_hall_id);
            if($halls->count()!==2||!$destination||!Auth::check()||
                (int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canManageHallInventory($actor,$destination))
                throw new AuthorizationException('Current receiving-hall assignment required.');
            $receipt=DB::table('hall_inventory_internal_loan_receipts')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('receiving_hall_id',$destination->id)->lockForUpdate()->first();
            if (!$receipt)throw new ConflictHttpException('Loan was not independently received.');
            $old=DB::table('hall_inventory_internal_loan_return_batches')
                ->where('company_id',$companyId)->where('id',$batchId)->first();
            if ($old) {
                if($old->receipt_id!==$receipt->id||(int)$old->returned_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->evidence_sha256,$sha)||
                    (int)$old->evidence_size!==$size||!$disk->exists($old->evidence_storage_path)||
                    !hash_equals($sha,hash('sha256',$disk->get($old->evidence_storage_path))))
                    throw new ConflictHttpException('Return batch ID conflicts with prior custody.');
                $oldQty=DB::table('hall_inventory_internal_loan_return_lines')
                    ->where('company_id',$companyId)->where('return_batch_id',$batchId)
                    ->pluck('quantity','source_item_id')->map(fn($q)=>(int)$q)->all();
                ksort($oldQty,SORT_STRING);
                if($oldQty!==$returnQuantities)
                    throw new ConflictHttpException('Return batch quantities changed on retry.');
                return $batchId;
            }
            $received=DB::table('hall_inventory_internal_loan_receipt_lines')
                ->where('company_id',$companyId)->where('receipt_id',$receipt->id)
                ->whereIn('source_item_id',array_keys($returnQuantities))
                ->orderBy('item_id')->get(['source_item_id','item_id','quantity']);
            if($received->count()!==count($returnQuantities))
                throw new ConflictHttpException('Return items must belong to this destination receipt.');
            $lineData=[];
            foreach($received as $line) {
                $src=(string)$line->source_item_id;
                $prior=DB::table('hall_inventory_internal_loan_return_lines as rl')
                    ->join('hall_inventory_internal_loan_return_batches as rb',
                        'rb.id','=','rl.return_batch_id')
                    ->where('rb.company_id',$companyId)->where('rb.receipt_id',$receipt->id)
                    ->where('rl.source_item_id',$src)->sum('rl.quantity');
                $qty=$returnQuantities[$src];
                if($qty+(int)$prior>(int)$line->quantity)
                    throw new ConflictHttpException('Return exceeds original independently received quantity.');
                $lineData[]=['sourceId'=>$src,'destId'=>(string)$line->item_id,'quantity'=>$qty];
            }
            usort($lineData,fn($a,$b)=>strcmp($a['destId'],$b['destId']));
            if(count($expectedRevisions)!==count($lineData))
                throw new ConflictHttpException('Every returned destination item needs an expected revision.');
            $positions=[];
            foreach($lineData as $line) {
                $destId=$line['destId'];
                if(!array_key_exists($destId,$expectedRevisions)||
                    !is_int($expectedRevisions[$destId])||$expectedRevisions[$destId]<0)
                    throw new ConflictHttpException('Invalid expected borrowed position revision.');
                $pos=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                    ->where('hall_id',$destination->id)->where('item_id',$destId)
                    ->lockForUpdate()->first();
                if(!$pos||(int)$pos->revision!==$expectedRevisions[$destId]||
                    (int)$pos->borrowed_usable<$line['quantity'])
                    throw new ConflictHttpException('Insufficient borrowed usable custody or stale revision.');
                $positions[$destId]=$pos;
            }
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Return evidence storage failed.');
            try {
                DB::table('hall_inventory_internal_loan_return_batches')->insert([
                    'id'=>$batchId,'company_id'=>$companyId,'loan_id'=>$loanId,
                    'receipt_id'=>$receipt->id,
                    'destination_hall_id'=>$destination->id,
                    'source_hall_id'=>$draft->lending_hall_id,
                    'evidence_sha256'=>$sha,'evidence_size'=>$size,
                    'evidence_storage_path'=>$key,'returned_by'=>$actor->getKey()
                ]);
                foreach($lineData as $line) {
                    $pos=$positions[$line['destId']];
                    $before=[
                        'ownedUsable'=>(int)$pos->owned_usable,
                        'ownedOnLoan'=>(int)$pos->owned_on_loan,
                        'ownedDamaged'=>(int)$pos->owned_damaged,
                        'borrowedUsable'=>(int)$pos->borrowed_usable,
                        'borrowedTransit'=>(int)$pos->borrowed_transit,
                        'borrowedDamaged'=>(int)$pos->borrowed_damaged
                    ];
                    $after=$this->positions->apply($before,'send_borrowed_back',$line['quantity']);
                    $rev=(int)$pos->revision;
                    $updated=DB::table('hall_inventory_positions')->where('id',$pos->id)
                        ->where('company_id',$companyId)->where('hall_id',$destination->id)
                        ->where('item_id',$line['destId'])->where('revision',$rev)->update([
                            'borrowed_usable'=>$after['borrowedUsable'],
                            'borrowed_transit'=>$after['borrowedTransit'],
                            'revision'=>$rev+1,'updated_at'=>now()
                        ]);
                    if($updated!==1)throw new ConflictHttpException('Concurrent return movement revision changed.');
                    $movement=(string)Str::uuid();
                    DB::table('hall_inventory_movement_events')->insert([
                        'id'=>$movement,'company_id'=>$companyId,'hall_id'=>$destination->id,
                        'item_id'=>$line['destId'],'position_id'=>$pos->id,
                        'event_kind'=>'send_borrowed_back','quantity'=>$line['quantity'],
                        'revision_before'=>$rev,'revision_after'=>$rev+1,
                        'request_fingerprint'=>hash('sha256',$batchId.$line['sourceId'].$sha),
                        'reason'=>'Independent partial return shipment '.$loanId,
                        'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                        'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                        'actor_id'=>$actor->getKey()
                    ]);
                    DB::table('hall_inventory_internal_loan_return_lines')->insert([
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,'return_batch_id'=>$batchId,
                        'destination_hall_id'=>$destination->id,'source_item_id'=>$line['sourceId'],
                        'item_id'=>$line['destId'],'movement_id'=>$movement,'quantity'=>$line['quantity']
                    ]);
                }
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                    'action'=>'hall_inventory.internal_loan_partial_return_sent',
                    'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
                    'before_values'=>json_encode(['state'=>'DESTINATION_RECEIVED_RETURN_PENDING'],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['state'=>'RETURN_IN_TRANSIT_SOURCE_ACK_PENDING',
                        'batchId'=>$batchId,'lineCount'=>count($lineData)],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['destinationHallId'=>$destination->id,
                        'sourceHallId'=>$draft->lending_hall_id,'attachmentHash'=>$sha],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e) {
                $disk->delete($key);
                throw $e;
            }
            return $batchId;
        },3);
    }
}
