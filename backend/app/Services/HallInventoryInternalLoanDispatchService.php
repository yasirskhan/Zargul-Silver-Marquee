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
 * Internal source dispatch ONLY. Destination receipt, borrowed custody and
 * every eventual return require separate independent workflows.
 * No loan completion or financial entry is created by this service.
 */
final class HallInventoryInternalLoanDispatchService
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $positions
    ) {}

    /** @param array<string,int> $expectedRevisions keyed by source catalog item UUID */
    public function dispatch(User $actor,string $companyId,string $loanId,
        string $dispatchId,array $expectedRevisions,UploadedFile $evidence):string
    {
        if (!config('inventory_controls.loan_drafts_enabled') ||
            !config('inventory_controls.loan_dispatch_enabled')) {
            throw new ConflictHttpException('Inventory physical dispatch is not released.');
        }
        if (!Str::isUuid($companyId)||!Str::isUuid($loanId)||!Str::isUuid($dispatchId)) {
            throw new InvalidArgumentException('Invalid loan dispatch references.');
        }
        $path=$evidence->getRealPath();
        $size=$evidence->getSize();
        if (!$evidence->isValid()||!$path||!is_file($path)||
            !is_int($size)||$size<1||$size>5242880 ||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/png','image/jpeg'],true)) {
            throw new InvalidArgumentException('Valid bounded physical handover attachment required.');
        }
        $sha=hash_file('sha256',$path);
        if(!is_string($sha))throw new ConflictHttpException('Evidence hashing failed.');
        $key='private/hall-loan-handover/'.$companyId.'/'.$loanId.'/'.$dispatchId;
        $storage=Storage::disk('local');

        return DB::transaction(function() use ($actor,$companyId,$loanId,$dispatchId,
            $expectedRevisions,$sha,$size,$key,$storage,$evidence):string {
            $draft=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$loanId)
                ->lockForUpdate()->first();
            if(!$draft||$draft->direction!=='internal') {
                throw new ConflictHttpException('Only internal loan drafts can use this dispatch path.');
            }
            $hallIds=[$draft->lending_hall_id,$draft->borrowing_hall_id];
            sort($hallIds,SORT_STRING);
            $halls=Hall::query()->where('company_id',$companyId)
                ->whereIn('id',$hallIds)->where('is_active',true)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source=$halls->get($draft->lending_hall_id);
            if($halls->count()!==2||!$source||!Auth::check()||
                (int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$source)) {
                throw new AuthorizationException('Only a distinct source hall owner/admin may dispatch.');
            }
            if((int)$draft->created_by===(int)$actor->getKey()) {
                throw new AuthorizationException('Draft creator cannot self-dispatch physical stock.');
            }
            $existing=DB::table('hall_inventory_loan_dispatches')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
            if($existing) {
                if((string)$existing->id!==$dispatchId ||
                    (int)$existing->dispatched_by!==(int)$actor->getKey() ||
                    !hash_equals((string)$existing->evidence_sha256,$sha) ||
                    (int)$existing->evidence_size!==$size ||
                    !$storage->exists((string)$existing->evidence_storage_path) ||
                    !hash_equals($sha,hash('sha256',$storage->get((string)$existing->evidence_storage_path)))) {
                    throw new ConflictHttpException('Loan already dispatched with different evidence.');
                }
                return $dispatchId;
            }
            $lines=DB::table('hall_inventory_loan_draft_lines')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->orderBy('item_id')->get(['item_id','requested_quantity','catalog_hall_id']);
            if($lines->isEmpty()||count($expectedRevisions)!==$lines->count()) {
                throw new ConflictHttpException('Every dispatch line requires its exact expected revision.');
            }
            $positions=[];
            foreach($lines as $line) {
                if($line->catalog_hall_id!==$draft->lending_hall_id ||
                    !array_key_exists((string)$line->item_id,$expectedRevisions)||
                    !is_int($expectedRevisions[$line->item_id])||
                    $expectedRevisions[$line->item_id]<0) {
                    throw new ConflictHttpException('Loan line scope or expected revisions differ.');
                }
                $pos=DB::table('hall_inventory_positions')
                    ->where('company_id',$companyId)
                    ->where('hall_id',$draft->lending_hall_id)
                    ->where('item_id',$line->item_id)->lockForUpdate()->first();
                if(!$pos||(int)$pos->revision!==$expectedRevisions[$line->item_id]||
                    (int)$pos->owned_usable<(int)$line->requested_quantity) {
                    throw new ConflictHttpException('Stale or insufficient owned source inventory.');
                }
                $positions[$line->item_id]=$pos;
            }
            if(!$storage->put($key,file_get_contents($evidence->getRealPath()))) {
                throw new ConflictHttpException('Private handover attachment storage failed.');
            }
            try {
                DB::table('hall_inventory_loan_dispatches')->insert([
                    'id'=>$dispatchId,'company_id'=>$companyId,'loan_id'=>$loanId,
                    'lending_hall_id'=>$draft->lending_hall_id,
                    'borrowing_hall_id'=>$draft->borrowing_hall_id,
                    'evidence_sha256'=>$sha,'evidence_size'=>$size,
                    'evidence_storage_path'=>$key,'dispatched_by'=>$actor->getKey()
                ]);
                foreach($lines as $line) {
                    $pos=$positions[$line->item_id];
                    $before=[
                        'ownedUsable'=>(int)$pos->owned_usable,
                        'ownedOnLoan'=>(int)$pos->owned_on_loan,
                        'ownedDamaged'=>(int)$pos->owned_damaged,
                        'borrowedUsable'=>(int)$pos->borrowed_usable,
                        'borrowedTransit'=>(int)$pos->borrowed_transit,
                        'borrowedDamaged'=>(int)$pos->borrowed_damaged
                    ];
                    $qty=(int)$line->requested_quantity;
                    $after=$this->positions->apply($before,'dispatch_owned_loan',$qty);
                    $revision=(int)$pos->revision;
                    $changed=DB::table('hall_inventory_positions')
                        ->where('id',$pos->id)->where('revision',$revision)
                        ->where('company_id',$companyId)
                        ->where('hall_id',$draft->lending_hall_id)
                        ->where('item_id',$line->item_id)->update([
                            'owned_usable'=>$after['ownedUsable'],
                            'owned_on_loan'=>$after['ownedOnLoan'],
                            'revision'=>$revision+1,'updated_at'=>now()
                        ]);
                    if($changed!==1)throw new ConflictHttpException('Concurrent loan dispatch revision conflict.');
                    $movementId=(string)Str::uuid();
                    DB::table('hall_inventory_movement_events')->insert([
                        'id'=>$movementId,'company_id'=>$companyId,
                        'hall_id'=>$draft->lending_hall_id,
                        'item_id'=>$line->item_id,'position_id'=>$pos->id,
                        'event_kind'=>'dispatch_owned_loan','quantity'=>$qty,
                        'revision_before'=>$revision,'revision_after'=>$revision+1,
                        'request_fingerprint'=>hash('sha256',$draft->request_fingerprint.$line->item_id.$sha),
                        'reason'=>'Independently dispatched loan '.$loanId,
                        'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                        'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                        'actor_id'=>$actor->getKey()
                    ]);
                    DB::table('hall_inventory_loan_dispatch_lines')->insert([
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                        'dispatch_id'=>$dispatchId,'hall_id'=>$draft->lending_hall_id,
                        'item_id'=>$line->item_id,'movement_id'=>$movementId,'quantity'=>$qty
                    ]);
                }
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'actor_id'=>$actor->getKey(),
                    'action'=>'hall_inventory.internal_loan_source_dispatched',
                    'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
                    'before_values'=>json_encode(['state'=>'DRAFT_ONLY'],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['state'=>'SOURCE_DISPATCH_RECORDED_RECEIPT_PENDING',
                        'dispatchId'=>$dispatchId,'lineCount'=>$lines->count()],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['source'=>$draft->lending_hall_id,
                        'target'=>$draft->borrowing_hall_id,
                        'attachmentHash'=>$sha],JSON_THROW_ON_ERROR)
                ]);
            } catch(\Throwable $e) {
                $storage->delete($key);
                throw $e;
            }
            return $dispatchId;
        },3);
    }
}
