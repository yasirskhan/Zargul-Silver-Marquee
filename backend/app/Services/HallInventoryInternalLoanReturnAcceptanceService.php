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
 * Independent source-hall physical acknowledgment of a previously shipped
 * partial internal return. Unresolved quantities remain BOTH on loan at
 * source and in transit at destination. Never closes a loan or posts money.
 */
final class HallInventoryInternalLoanReturnAcceptanceService
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $positions
    ) {}

    /**
     * @param array<string,array{good:int,damaged:int}> $conditionBySource
     * @param array<string,int> $sourceRevisions source catalog UUID => revision
     * @param array<string,int> $destRevisions destination catalog UUID => revision
     */
    public function accept(User $actor,string $companyId,string $batchId,string $acceptanceId,
        array $conditionBySource,array $sourceRevisions,array $destRevisions,
        UploadedFile $evidence):string
    {
        if(!config('inventory_controls.loan_drafts_enabled')||
            !config('inventory_controls.loan_dispatch_enabled')||
            !config('inventory_controls.loan_receipt_enabled')||
            !config('inventory_controls.loan_returns_enabled')||
            !config('inventory_controls.loan_return_acceptance_enabled'))
            throw new ConflictHttpException('Independent return receipt acceptance is disabled.');
        if(!Str::isUuid($companyId)||!Str::isUuid($batchId)||!Str::isUuid($acceptanceId)||
            count($conditionBySource)<1||count($conditionBySource)>100)
            throw new InvalidArgumentException('Invalid returned stock acceptance scope.');
        foreach($conditionBySource as $itemId=>$counts) {
            if(!is_string($itemId)||!Str::isUuid($itemId)||!is_array($counts)||
                array_is_list($counts)||count($counts)!==2||
                !array_key_exists('good',$counts)||!array_key_exists('damaged',$counts)||
                !is_int($counts['good'])||!is_int($counts['damaged'])||
                $counts['good']<0||$counts['damaged']<0||
                $counts['good']>100000000||$counts['damaged']>100000000)
                throw new InvalidArgumentException('Every return line must have nonnegative physical good and damaged counts.');
        }
        ksort($conditionBySource,SORT_STRING);
        $path=$evidence->getRealPath();
        $size=$evidence->getSize();
        if(!$evidence->isValid()||!$path||!is_file($path)||!is_int($size)||
            $size<1||$size>5242880||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/jpeg','image/png'],true))
            throw new InvalidArgumentException('Physical return source acknowledgment attachment required.');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Return source acceptance evidence unreadable.');
        $sha=hash('sha256',$bytes);
        $fingerprint=hash('sha256',json_encode([$actor->getKey(),$companyId,
            $batchId,$conditionBySource,$sha,$size],JSON_THROW_ON_ERROR));
        $disk=Storage::disk('local');
        $key='private/hall-loan-return-acceptance/'.$companyId.'/'.$batchId.'/'.$acceptanceId;

        return DB::transaction(function()use($actor,$companyId,$batchId,$acceptanceId,
            $conditionBySource,$sourceRevisions,$destRevisions,$bytes,$size,$sha,$fingerprint,$disk,$key):string {
            $batch=DB::table('hall_inventory_internal_loan_return_batches')
                ->where('company_id',$companyId)->where('id',$batchId)->lockForUpdate()->first();
            if(!$batch)throw new ConflictHttpException('Original return batch was not found.');
            $draft=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$batch->loan_id)
                ->lockForUpdate()->first();
            if(!$draft||$draft->direction!=='internal'||
                $draft->lending_hall_id!==$batch->source_hall_id||
                $draft->borrowing_hall_id!==$batch->destination_hall_id)
                throw new ConflictHttpException('Return batch source/destination mismatched original draft.');
            $hallIds=[$draft->lending_hall_id,$draft->borrowing_hall_id];
            sort($hallIds,SORT_STRING);
            $halls=Hall::query()->where('company_id',$companyId)
                ->whereIn('id',$hallIds)->where('is_active',true)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source=$halls->get($draft->lending_hall_id);
            if($halls->count()!==2||!$source||!Auth::check()||
                (int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$source)||
                (int)$batch->returned_by===(int)$actor->getKey())
                throw new AuthorizationException('Independent active source owner/admin must accept returned stock.');
            $old=DB::table('hall_inventory_internal_loan_return_acceptances')
                ->where('company_id',$companyId)->where('return_batch_id',$batchId)->first();
            if($old) {
                if((string)$old->id!==$acceptanceId||
                    (int)$old->accepted_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->review_fingerprint,$fingerprint)||
                    !$disk->exists((string)$old->evidence_storage_path)||
                    !hash_equals($sha,hash('sha256',$disk->get((string)$old->evidence_storage_path))))
                    throw new ConflictHttpException('Return was already reviewed with different evidence.');
                return $acceptanceId;
            }
            $lines=DB::table('hall_inventory_internal_loan_return_lines')
                ->where('company_id',$companyId)->where('return_batch_id',$batchId)
                ->orderBy('source_item_id')->get(['source_item_id','item_id','quantity']);
            if($lines->isEmpty()||$lines->count()!==count($conditionBySource)||
                $lines->count()!==count($sourceRevisions)||
                $lines->count()!==count($destRevisions))
                throw new ConflictHttpException('Full return batch line mapping and revisions required.');
            $received=[];
            $locks=[];
            foreach($lines as $line) {
                $sourceItem=(string)$line->source_item_id;
                $destItem=(string)$line->item_id;
                $condition=$conditionBySource[$sourceItem]??null;
                if($condition===null||($condition['good']+$condition['damaged'])>(int)$line->quantity)
                    throw new ConflictHttpException('Acknowledged condition exceeds shipped quantity.');
                if(!array_key_exists($sourceItem,$sourceRevisions)||
                    !array_key_exists($destItem,$destRevisions)||
                    !is_int($sourceRevisions[$sourceItem])||
                    !is_int($destRevisions[$destItem])||
                    $sourceRevisions[$sourceItem]<0||$destRevisions[$destItem]<0)
                    throw new ConflictHttpException('Expected source and destination stock revisions required.');
                $received[]=['src'=>$sourceItem,'dst'=>$destItem,
                    'sent'=>(int)$line->quantity,'good'=>$condition['good'],
                    'damaged'=>$condition['damaged'],
                    'accepted'=>$condition['good']+$condition['damaged']];
                $locks[]=['hall'=>$draft->lending_hall_id,'item'=>$sourceItem];
                $locks[]=['hall'=>$draft->borrowing_hall_id,'item'=>$destItem];
            }
            usort($locks,fn($a,$b)=>strcmp($a['hall'].'/'.$a['item'],$b['hall'].'/'.$b['item']));
            $positions=[];
            foreach($locks as $lock) {
                $pos=DB::table('hall_inventory_positions')
                    ->where('company_id',$companyId)->where('hall_id',$lock['hall'])
                    ->where('item_id',$lock['item'])->lockForUpdate()->first();
                if(!$pos)throw new ConflictHttpException('Expected return stock position is missing.');
                $positions[$lock['hall'].'/'.$lock['item']]=$pos;
            }
            foreach($received as $line) {
                $src=$positions[$draft->lending_hall_id.'/'.$line['src']];
                $dest=$positions[$draft->borrowing_hall_id.'/'.$line['dst']];
                if((int)$src->revision!==$sourceRevisions[$line['src']]||
                    (int)$dest->revision!==$destRevisions[$line['dst']]||
                    (int)$src->owned_on_loan<$line['accepted']||
                    (int)$dest->borrowed_transit<$line['accepted'])
                    throw new ConflictHttpException('Changed return position or insufficient outstanding custody.');
            }
            if(!$disk->exists((string)$batch->evidence_storage_path))
                throw new ConflictHttpException('Return shipment evidence missing.');
            $priorBytes=$disk->get((string)$batch->evidence_storage_path);
            if(!is_string($priorBytes)||strlen($priorBytes)!==(int)$batch->evidence_size||
                !hash_equals((string)$batch->evidence_sha256,hash('sha256',$priorBytes)))
                throw new ConflictHttpException('Original return shipment evidence failed integrity check.');
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Source acknowledgement evidence storage failed.');
            try {
                DB::table('hall_inventory_internal_loan_return_acceptances')->insert([
                    'id'=>$acceptanceId,'company_id'=>$companyId,
                    'return_batch_id'=>$batchId,'source_hall_id'=>$source->id,
                    'review_fingerprint'=>$fingerprint,
                    'evidence_sha256'=>$sha,'evidence_size'=>$size,
                    'evidence_storage_path'=>$key,'accepted_by'=>$actor->getKey()
                ]);
                foreach($received as $line) {
                    $src=$positions[$draft->lending_hall_id.'/'.$line['src']];
                    $dest=$positions[$draft->borrowing_hall_id.'/'.$line['dst']];
                    $srcState=[
                        'ownedUsable'=>(int)$src->owned_usable,
                        'ownedOnLoan'=>(int)$src->owned_on_loan,
                        'ownedDamaged'=>(int)$src->owned_damaged,
                        'borrowedUsable'=>(int)$src->borrowed_usable,
                        'borrowedTransit'=>(int)$src->borrowed_transit,
                        'borrowedDamaged'=>(int)$src->borrowed_damaged
                    ];
                    $destState=[
                        'ownedUsable'=>(int)$dest->owned_usable,
                        'ownedOnLoan'=>(int)$dest->owned_on_loan,
                        'ownedDamaged'=>(int)$dest->owned_damaged,
                        'borrowedUsable'=>(int)$dest->borrowed_usable,
                        'borrowedTransit'=>(int)$dest->borrowed_transit,
                        'borrowedDamaged'=>(int)$dest->borrowed_damaged
                    ];
                    $sourceRev=(int)$src->revision;
                    $destRev=(int)$dest->revision;
                    $goodEvent=null;$damageEvent=null;$destEvent=null;
                    foreach([['return_owned_good',$line['good']],
                        ['return_owned_damaged',$line['damaged']]] as [$kind,$qty]) {
                        if($qty===0)continue;
                        $next=$this->positions->apply($srcState,$kind,$qty);
                        $updated=DB::table('hall_inventory_positions')
                            ->where('id',$src->id)->where('company_id',$companyId)
                            ->where('hall_id',$source->id)->where('item_id',$line['src'])
                            ->where('revision',$sourceRev)->update([
                                'owned_usable'=>$next['ownedUsable'],
                                'owned_on_loan'=>$next['ownedOnLoan'],
                                'owned_damaged'=>$next['ownedDamaged'],
                                'revision'=>$sourceRev+1,'updated_at'=>now()
                            ]);
                        if($updated!==1)throw new ConflictHttpException('Source return revision changed.');
                        $movementId=(string)Str::uuid();
                        DB::table('hall_inventory_movement_events')->insert([
                            'id'=>$movementId,'company_id'=>$companyId,'hall_id'=>$source->id,
                            'item_id'=>$line['src'],'position_id'=>$src->id,
                            'event_kind'=>$kind,'quantity'=>$qty,
                            'revision_before'=>$sourceRev,'revision_after'=>$sourceRev+1,
                            'request_fingerprint'=>hash('sha256',$acceptanceId.$line['src'].$kind.$sha),
                            'reason'=>'Independently counted loan return '.$batchId,
                            'before_values'=>json_encode($srcState,JSON_THROW_ON_ERROR),
                            'after_values'=>json_encode($next,JSON_THROW_ON_ERROR),
                            'actor_id'=>$actor->getKey()
                        ]);
                        if($kind==='return_owned_good')$goodEvent=$movementId;
                        else $damageEvent=$movementId;
                        $srcState=$next;$sourceRev++;
                    }
                    if($line['accepted']>0) {
                        $next=$this->positions->apply($destState,'confirm_borrowed_return',$line['accepted']);
                        $updated=DB::table('hall_inventory_positions')
                            ->where('id',$dest->id)->where('company_id',$companyId)
                            ->where('hall_id',$draft->borrowing_hall_id)
                            ->where('item_id',$line['dst'])->where('revision',$destRev)->update([
                                'borrowed_transit'=>$next['borrowedTransit'],
                                'revision'=>$destRev+1,'updated_at'=>now()
                            ]);
                        if($updated!==1)throw new ConflictHttpException('Destination return revision changed.');
                        $destEvent=(string)Str::uuid();
                        DB::table('hall_inventory_movement_events')->insert([
                            'id'=>$destEvent,'company_id'=>$companyId,
                            'hall_id'=>$draft->borrowing_hall_id,
                            'item_id'=>$line['dst'],'position_id'=>$dest->id,
                            'event_kind'=>'confirm_borrowed_return','quantity'=>$line['accepted'],
                            'revision_before'=>$destRev,'revision_after'=>$destRev+1,
                            'request_fingerprint'=>hash('sha256',$acceptanceId.$line['dst'].'borrowed'.$sha),
                            'reason'=>'Source confirmed partial return '.$batchId,
                            'before_values'=>json_encode($destState,JSON_THROW_ON_ERROR),
                            'after_values'=>json_encode($next,JSON_THROW_ON_ERROR),
                            'actor_id'=>$actor->getKey()
                        ]);
                    }
                    DB::table('hall_inventory_internal_loan_return_acceptance_lines')->insert([
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                        'acceptance_id'=>$acceptanceId,'source_hall_id'=>$source->id,
                        'source_item_id'=>$line['src'],'dest_item_id'=>$line['dst'],
                        'sent_quantity'=>$line['sent'],'good_quantity'=>$line['good'],
                        'damaged_quantity'=>$line['damaged'],
                        'unresolved_quantity'=>$line['sent']-$line['accepted'],
                        'source_good_movement_id'=>$goodEvent,
                        'source_damage_movement_id'=>$damageEvent,
                        'destination_movement_id'=>$destEvent
                    ]);
                }
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                    'action'=>'hall_inventory.internal_loan_partial_return_accepted',
                    'entity_type'=>'hall_inventory_internal_loan_return_batch','entity_id'=>$batchId,
                    'before_values'=>json_encode(['state'=>'RETURN_IN_TRANSIT_SOURCE_ACK_PENDING'],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['state'=>'SOURCE_ACK_RECORDED_OUTSTANDING_REVIEW_REQUIRED',
                        'acceptanceId'=>$acceptanceId,'lineCount'=>count($received)],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['source'=>$source->id,
                        'destination'=>$draft->borrowing_hall_id,'attachmentHash'=>$sha],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e) {
                $disk->delete($key);
                throw $e;
            }
            return $acceptanceId;
        },3);
    }
}
