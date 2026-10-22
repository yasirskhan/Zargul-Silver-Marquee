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
 * Partner-signed short/damaged return counts and independent documentary
 * review. This NEVER clears borrowed transit, retires stock, creates a
 * liability, posts a journal, waives a debt or closes a loan. The partner's
 * signed shortfall stays visible as unresolved custody for the separate
 * financial and closure workflows.
 */
final class HallInventoryExternalBorrowedDiscrepancyService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate():void {
        foreach(['loan_drafts_enabled','external_borrowed_intake_enabled',
            'external_borrowed_return_enabled','external_borrowed_discrepancy_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new ConflictHttpException('Outside partner return discrepancy evidence is not released.');
    }
    private function ids(string ...$ids):void {
        foreach($ids as $id) if(!Str::isUuid($id))
            throw new InvalidArgumentException('Exact partner return UUID references are required.');
    }
    private function evidence(UploadedFile $file):array {
        $path=$file->getRealPath(); $size=$file->getSize();
        if(!$file->isValid()||!$path||!is_file($path)||!is_int($size)||$size<1||$size>5242880||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/jpeg','image/png'],true))
            throw new InvalidArgumentException('Partner-signed short/damaged return evidence must be PDF/photo, 5 MB maximum.');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Partner discrepancy evidence could not be read.');
        return [$bytes,$size,hash('sha256',$bytes)];
    }
    private function verified(object $row):void {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->evidence_storage_path))
            throw new ConflictHttpException('Signed partner discrepancy evidence is missing.');
        $bytes=$disk->get((string)$row->evidence_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
            !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Signed partner discrepancy evidence failed integrity verification.');
    }
    private function scope(User $actor,string $companyId,string $hallId,bool $configure): Hall {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->lockForUpdate()->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !($configure?$this->access->canConfigureHall($actor,$hall):
                $this->access->canManageHallInventory($actor,$hall)))
            throw new AuthorizationException('Current borrowing-hall inventory permission required.');
        return $hall;
    }
    /**
     * @param array<string,array{received:int,damaged:int}> $counts
     *        Every shipped item is required; received includes damaged units.
     */
    public function report(User $actor,string $companyId,string $hallId,string $batchId,
        string $discrepancyId,array $counts,string $reason,UploadedFile $file):string {
        $this->gate();
        $this->ids($companyId,$hallId,$batchId,$discrepancyId);
        $reason=trim($reason);
        if(mb_strlen($reason)<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Document the partner short or damaged return count.');
        if(count($counts)<1||count($counts)>100)
            throw new InvalidArgumentException('Record every returned item with partner counts.');
        $normalized=[];
        foreach($counts as $itemId=>$line) {
            if(!is_string($itemId)||!Str::isUuid($itemId)||!is_array($line)||
                !array_key_exists('received',$line)||!array_key_exists('damaged',$line))
                throw new InvalidArgumentException('Partner discrepancy lines require an item, received and damaged quantity.');
            $received=$line['received']; $damaged=$line['damaged'];
            if(!is_int($received)||!is_int($damaged)||
                $received<0||$received>100000000||$damaged<0||$damaged>100000000)
                throw new InvalidArgumentException('Partner received and damaged counts must be nonnegative whole units.');
            $normalized[$itemId]=['received'=>$received,'damaged'=>$damaged];
        }
        ksort($normalized,SORT_STRING);
        [$bytes,$size,$sha]=$this->evidence($file);
        $disk=Storage::disk('local');
        $key='private/hall-external-discrepancy/'.$companyId.'/'.$batchId.'/'.$discrepancyId;
        return DB::transaction(function()use($actor,$companyId,$hallId,$batchId,$discrepancyId,
            $normalized,$reason,$bytes,$size,$sha,$disk,$key):string {
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',DB::table('hall_inventory_external_in_return_batches')
                    ->where('company_id',$companyId)->where('hall_id',$hallId)
                    ->where('id',$batchId)->value('loan_id'))
                ->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||
                (string)$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Partner discrepancy must belong to the exact borrowing-hall loan.');
            $this->scope($actor,$companyId,$hallId,true);
            $batch=DB::table('hall_inventory_external_in_return_batches')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$batchId)->lockForUpdate()->first();
            if(!$batch||(string)$batch->loan_id!==(string)$loan->id)
                throw new ConflictHttpException('Signed partner return batch was not found for this loan.');
            if((int)$batch->dispatched_by===(int)$actor->getKey())
                throw new AuthorizationException('Return dispatcher needs a separate owner/admin to record partner short counts.');
            if(DB::table('hall_inventory_external_in_return_confirmations')
                ->where('company_id',$companyId)->where('batch_id',$batchId)->exists())
                throw new ConflictHttpException('Partner receipt is already exactly confirmed; no discrepancy record is applicable.');
            $shipped=DB::table('hall_inventory_external_in_return_lines')
                ->where('company_id',$companyId)->where('hall_id',$hallId)->where('batch_id',$batchId)
                ->orderBy('item_id')->pluck('quantity','item_id');
            if($shipped->isEmpty()||$shipped->count()!==count($normalized))
                throw new ConflictHttpException('Every original return line needs one partner short/damaged count.');
            $lines=[]; $any=false;
            foreach($shipped as $itemId=>$quantity) {
                $itemId=(string)$itemId; $quantity=(int)$quantity;
                if(!array_key_exists($itemId,$normalized))
                    throw new ConflictHttpException('Partner count does not cover the exact returned item set.');
                $received=$normalized[$itemId]['received'];
                $damaged=$normalized[$itemId]['damaged'];
                if($received>$quantity||$damaged>$received)
                    throw new ConflictHttpException('Partner count exceeds the original shipped quantity or damaged exceeds received.');
                if($received<$quantity||$damaged>0)$any=true;
                $lines[$itemId]=['shipped'=>$quantity,'received'=>$received,'damaged'=>$damaged];
            }
            if(!$any)
                throw new ConflictHttpException('No partner shortfall or damaged unit was recorded.');
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$batchId,
                $discrepancyId,$lines,$reason,$sha,(int)$actor->getKey()],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_external_in_discrepancies')
                ->where('company_id',$companyId)->where('id',$discrepancyId)->first();
            if($old) {
                if(!hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Conflicting signed partner discrepancy replay.');
                $this->verified($old);
                return $discrepancyId;
            }
            if(DB::table('hall_inventory_external_in_discrepancies')
                ->where('company_id',$companyId)->where('batch_id',$batchId)->exists())
                throw new ConflictHttpException('A signed partner discrepancy already exists for this return batch.');
            if($disk->exists($key))
                throw new ConflictHttpException('Partner discrepancy evidence storage key already occupied.');
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Partner short/damaged evidence could not be stored.');
            try {
                DB::table('hall_inventory_external_in_discrepancies')->insert([
                    'id'=>$discrepancyId,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'loan_id'=>$loan->id,'batch_id'=>$batchId,'reason'=>$reason,
                    'fingerprint'=>$fingerprint,'evidence_sha256'=>$sha,'evidence_size'=>$size,
                    'evidence_storage_path'=>$key,'reported_by'=>$actor->getKey()
                ]);
                foreach($lines as $itemId=>$line)
                    DB::table('hall_inventory_external_in_discrepancy_lines')->insert([
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,'hall_id'=>$hallId,
                        'discrepancy_id'=>$discrepancyId,'item_id'=>$itemId,
                        'shipped_quantity'=>$line['shipped'],
                        'received_quantity'=>$line['received'],
                        'damaged_quantity'=>$line['damaged']
                    ]);
                $this->audit($actor,$companyId,'reported',$discrepancyId,(string)$loan->id,
                    ['batchId'=>$batchId,'partnerCounts'=>$normalized,'evidenceSha256'=>$sha,
                     'stockCleared'=>false,'moneyPosted'=>false,'loanClosed'=>false]);
            }catch(\Throwable $e){$disk->delete($key);throw $e;}
            return $discrepancyId;
        },3);
    }
    public function decide(User $actor,string $companyId,string $hallId,string $discrepancyId,
        string $decisionId,string $decision,string $reason):string {
        $this->gate();
        $this->ids($companyId,$hallId,$discrepancyId,$decisionId);
        if(!in_array($decision,['substantiated','rejected','requires_more_evidence'],true)||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independent partner discrepancy decision and reason required.');
        $reason=trim($reason);
        return DB::transaction(function()use($actor,$companyId,$hallId,$discrepancyId,
            $decisionId,$decision,$reason):string {
            $ref=DB::table('hall_inventory_external_in_discrepancies')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$discrepancyId)->first(['loan_id','batch_id']);
            if(!$ref)throw new AuthorizationException('Signed partner discrepancy is outside this hall.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$ref->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||
                (string)$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Partner discrepancy no longer belongs to the original borrowing-hall loan.');
            $this->scope($actor,$companyId,$hallId,true);
            $discrepancy=DB::table('hall_inventory_external_in_discrepancies')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$discrepancyId)->lockForUpdate()->first();
            if(!$discrepancy)throw new ConflictHttpException('Signed partner discrepancy was not found.');
            if((int)$discrepancy->reported_by===(int)$actor->getKey())
                throw new AuthorizationException('Partner-count recorder cannot independently decide their own evidence.');
            $batch=DB::table('hall_inventory_external_in_return_batches')
                ->where('company_id',$companyId)->where('id',$discrepancy->batch_id)->first();
            if($batch && (int)$batch->dispatched_by===(int)$actor->getKey())
                throw new AuthorizationException('Return dispatcher cannot decide partner discrepancy evidence.');
            if(DB::table('hall_inventory_external_in_return_confirmations')
                ->where('company_id',$companyId)->where('batch_id',$discrepancy->batch_id)->exists())
                throw new ConflictHttpException('Partner receipt was exactly confirmed; discrepancy review is closed.');
            $this->verified($discrepancy);
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$discrepancyId,
                (int)$actor->getKey(),$decision,$reason],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_external_in_discrepancy_decisions')
                ->where('company_id',$companyId)->where('discrepancy_id',$discrepancyId)->first();
            if($old) {
                if((string)$old->id!==$decisionId||
                    (int)$old->decided_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Signed partner discrepancy already has an immutable decision.');
                return $decisionId;
            }
            DB::table('hall_inventory_external_in_discrepancy_decisions')->insert([
                'id'=>$decisionId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'discrepancy_id'=>$discrepancyId,'decision'=>$decision,'reason'=>$reason,
                'fingerprint'=>$fingerprint,'decided_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'reviewed',$decisionId,(string)$loan->id,
                ['discrepancyId'=>$discrepancyId,'batchId'=>$discrepancy->batch_id,
                 'decision'=>$decision,'stockCleared'=>false,'moneyPosted'=>false,
                 'loanClosed'=>false]);
            return $decisionId;
        },3);
    }
    /** Read-only partner discrepancy history for current hall inventory staff. */
    public function recent(User $actor,string $companyId,string $hallId):array {
        $this->gate();
        $this->ids($companyId,$hallId);
        $this->scope($actor,$companyId,$hallId,false);
        $rows=DB::table('hall_inventory_external_in_discrepancies as d')
            ->leftJoin('hall_inventory_external_in_discrepancy_decisions as x',function($j):void {
                $j->on('x.company_id','=','d.company_id')->on('x.discrepancy_id','=','d.id');
            })->where('d.company_id',$companyId)->where('d.hall_id',$hallId)
            ->orderByDesc('d.created_at')->orderByDesc('d.id')->limit(40)
            ->get(['d.id','d.batch_id','d.loan_id','d.reason','d.reported_by',
                'd.evidence_sha256','d.created_at','x.decision','x.reason as review_reason',
                'x.decided_by']);
        if($rows->isEmpty())return [];
        $lines=DB::table('hall_inventory_external_in_discrepancy_lines')
            ->where('company_id',$companyId)->whereIn('discrepancy_id',$rows->pluck('id')->all())
            ->orderBy('item_id')->get(['discrepancy_id','item_id','shipped_quantity',
                'received_quantity','damaged_quantity'])->groupBy('discrepancy_id');
        return $rows->map(static function($row)use($lines):array {
            $items=($lines->get($row->id)??collect())->map(static fn($line):array=>[
                'itemId'=>(string)$line->item_id,
                'shippedQuantity'=>(int)$line->shipped_quantity,
                'receivedQuantity'=>(int)$line->received_quantity,
                'damagedQuantity'=>(int)$line->damaged_quantity,
                'missingQuantity'=>max(0,(int)$line->shipped_quantity-(int)$line->received_quantity),
            ])->all();
            return [
                'id'=>(string)$row->id,'batchId'=>(string)$row->batch_id,
                'loanId'=>(string)$row->loan_id,'reason'=>(string)$row->reason,
                'reportedBy'=>(int)$row->reported_by,'createdAt'=>(string)$row->created_at,
                'evidenceSha256'=>(string)$row->evidence_sha256,
                'decision'=>$row->decision,'reviewReason'=>$row->review_reason,
                'decidedBy'=>$row->decided_by,'lines'=>$items,
                'totalShipped'=>array_sum(array_column($items,'shippedQuantity')),
                'totalReceived'=>array_sum(array_column($items,'receivedQuantity')),
                'totalDamaged'=>array_sum(array_column($items,'damagedQuantity')),
                'totalMissing'=>array_sum(array_column($items,'missingQuantity')),
                'stockCleared'=>false,'moneyPosted'=>false,'loanClosed'=>false,
            ];
        })->all();
    }
    private function audit(User $actor,string $companyId,string $action,string $id,string $loanId,array $data):void {
        DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.external_borrowed_discrepancy_'.$action,
            'entity_type'=>'hall_inventory_external_in_discrepancy','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId],JSON_THROW_ON_ERROR)]);
    }
}
