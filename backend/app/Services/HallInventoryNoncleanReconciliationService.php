<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Read-only, original-loan-scoped reconciliation for non-clean outside-owned lending.
 * Reconciliation is NOT a write-off, settlement approval or final closure.
 */
final class HallInventoryNoncleanReconciliationService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function verifyEvidence(object $row):void
    {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->evidence_storage_path))
            throw new ConflictHttpException('Original signed inventory custody evidence is missing.');
        $bytes=$disk->get((string)$row->evidence_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
            !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Original signed inventory custody evidence failed integrity verification.');
    }

    public function report(User $actor,string $companyId,string $hallId,string $loanId):array
    {
        if(!config('inventory_controls.loan_nonclean_reconciliation_enabled')||
            !config('inventory_controls.loan_drafts_enabled')||
            !config('inventory_controls.external_out_dispatch_enabled')||
            !config('inventory_controls.external_out_return_enabled')||
            !config('inventory_controls.loan_incidents_enabled')||
            !config('inventory_controls.loan_incident_reviews_enabled')||
            !config('inventory_controls.loan_financial_claims_enabled'))
            throw new ConflictHttpException('Non-clean reconciliation has not been released.');
        foreach([$companyId,$hallId,$loanId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact inventory loan scope required.');

        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Private non-clean reconciliation requires current hall owner/admin.');
        $loan=DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$companyId)->where('id',$loanId)
            ->where('lending_hall_id',$hallId)->where('direction','external_out')->first();
        if(!$loan)throw new AuthorizationException('Loan does not belong to the selected original lending hall.');
        $dispatch=DB::table('hall_inventory_external_out_dispatches')
            ->where('company_id',$companyId)->where('loan_id',$loanId)
            ->where('hall_id',$hallId)->first();
        if(!$dispatch)throw new ConflictHttpException('Original independently approved dispatch is missing.');
        $handover=DB::table('hall_inventory_external_out_handovers')
            ->where('company_id',$companyId)->where('id',$dispatch->handover_id)
            ->where('loan_id',$loanId)->where('hall_id',$hallId)->first();
        if(!$handover)throw new ConflictHttpException('Original signed outside dispatch is missing.');
        $this->verifyEvidence($handover);
        $original=DB::table('hall_inventory_external_out_dispatch_lines')
            ->where('company_id',$companyId)->where('hall_id',$hallId)
            ->where('dispatch_id',$dispatch->id)->orderBy('item_id')->get();
        $signed=DB::table('hall_inventory_external_out_handover_lines')
            ->where('company_id',$companyId)->where('hall_id',$hallId)
            ->where('handover_id',$handover->id)->get()->keyBy('item_id');
        if($original->isEmpty()||$original->count()!==$signed->count()||
            $original->pluck('item_id')->unique()->count()!==$original->count())
            throw new ConflictHttpException('Original dispatch item provenance is incomplete.');
        $items=[];
        foreach($original as $line) {
            $id=(string)$line->item_id;$qty=(int)$line->quantity;
            if($qty<1||!$signed->has($id)||
                (int)$signed[$id]->counted_quantity!==$qty)
                throw new ConflictHttpException('Outside-owned dispatch does not match signed physical count.');
            $items[$id]=['itemId'=>$id,'dispatched'=>$qty,'received'=>0,
                'good'=>0,'damaged'=>0,'unresolved'=>0,'pendingReview'=>0,
                'unreturned'=>0,'onLoan'=>0];
        }
        $receipts=DB::table('hall_inventory_external_out_return_receipts')
            ->where('company_id',$companyId)->where('loan_id',$loanId)
            ->where('hall_id',$hallId)->orderBy('id')->get();
        $postedMovementIds=[];
        foreach($receipts as $receipt) {
            if($receipt->dispatch_id!==$dispatch->id)
                throw new ConflictHttpException('Return receipt references another dispatch.');
            $this->verifyEvidence($receipt);
            $review=DB::table('hall_inventory_external_out_return_reviews')
                ->where('company_id',$companyId)->where('receipt_id',$receipt->id)
                ->where('hall_id',$hallId)->where('loan_id',$loanId)->first();
            if($review&&(int)$review->reviewed_by===(int)$receipt->received_by)
                throw new ConflictHttpException('Physical return lacks independent condition review.');
            $received=DB::table('hall_inventory_external_out_return_lines')
                ->where('company_id',$companyId)->where('receipt_id',$receipt->id)
                ->where('hall_id',$hallId)->where('loan_id',$loanId)->get()->keyBy('item_id');
            $assessments=$review?DB::table('hall_inventory_external_out_return_review_lines')
                ->where('company_id',$companyId)->where('review_id',$review->id)
                ->where('receipt_id',$receipt->id)->where('hall_id',$hallId)
                ->get()->keyBy('item_id'):collect();
            if($received->isEmpty()||
                DB::table('hall_inventory_external_out_return_lines')->where('company_id',$companyId)
                    ->where('receipt_id',$receipt->id)->where('hall_id',$hallId)
                    ->where('loan_id',$loanId)->count()!==$received->count()||
                ($review&&$received->count()!==$assessments->count()))
                throw new ConflictHttpException('Outside return evidence and condition lines do not reconcile.');
            foreach($received as $itemId=>$line){
                $id=(string)$itemId;$qty=(int)$line->quantity;
                if(!isset($items[$id])||$qty<1||
                    ($items[$id]['received']+$qty)>$items[$id]['dispatched'])
                    throw new ConflictHttpException('Return quantity exceeds original dispatch.');
                $items[$id]['received']+=$qty;
                if(!$review){$items[$id]['pendingReview']+=$qty;continue;}
                if(!$assessments->has($id))
                    throw new ConflictHttpException('Independently reviewed return item missing.');
                $a=$assessments[$id];
                $good=(int)$a->good_quantity;
                $damaged=(int)$a->damaged_quantity;
                $unresolved=(int)$a->unresolved_quantity;
                if($good<0||$damaged<0||$unresolved<0||
                    $good+$damaged+$unresolved!==$qty||
                    ($good>0&&!$a->good_movement_id)||
                    ($damaged>0&&!$a->damaged_movement_id))
                    throw new ConflictHttpException('Independently reviewed physical quantities or movements invalid.');
                // A condition label alone cannot certify a posted book movement.
                // Verify the exact original batch, actor, unit count and kind.
                foreach([[$good,$a->good_movement_id,'return_owned_good'],
                         [$damaged,$a->damaged_movement_id,'return_owned_damaged']]
                         as [$number,$movementId,$kind]){
                    if($number===0){
                        if($movementId!==null)
                            throw new ConflictHttpException('Zero accepted units carried a posted movement.');
                        continue;
                    }
                    if(isset($postedMovementIds[(string)$movementId]))
                        throw new ConflictHttpException('One inventory movement cannot settle multiple physical return lines.');
                    $postedMovementIds[(string)$movementId]=true;
                    $movement=DB::table('hall_inventory_movement_events')
                        ->where('company_id',$companyId)->where('hall_id',$hallId)
                        ->where('item_id',$id)->where('id',$movementId)
                        ->where('event_kind',$kind)->where('quantity',$number)
                        ->where('actor_id',$review->reviewed_by)->first();
                    if(!$movement||$movement->reason!=='Independently assessed outside owned return '.$receipt->id)
                        throw new ConflictHttpException('Physical condition approval lacks exact immutable inventory movement.');
                }
                $items[$id]['good']+=$good;
                $items[$id]['damaged']+=$damaged;
                $items[$id]['unresolved']+=$unresolved;
            }
        }
        // Authorized loss disposal changes stock ownership, not physical
        // return evidence or automatically accepted cash recovery.
        $writtenOff=[];
        if(config('inventory_controls.loan_nonclean_writeoffs_enabled')) {
            $posted=DB::table('hall_inventory_nonclean_writeoff_proposals as p')
                ->join('hall_inventory_nonclean_writeoff_decisions as d',function($j):void {
                    $j->on('d.company_id','=','p.company_id')->on('d.proposal_id','=','p.id');
                })->where('p.company_id',$companyId)->where('p.hall_id',$hallId)
                ->where('p.loan_id',$loanId)->where('d.decision','approved')
                ->get(['p.item_id','p.kind','p.quantity']);
            foreach($posted as $w) {
                $item=(string)$w->item_id;
                if(!isset($items[$item]))throw new ConflictHttpException('Write-off references another physical loan item.');
                $writtenOff[$item][$w->kind]=($writtenOff[$item][$w->kind]??0)+(int)$w->quantity;
            }
        }
        foreach($items as &$item){
            $item['unreturned']=$item['dispatched']-$item['received'];
            // Unresolved returns and unreviewed receipts cannot be cleared
            // from the on-loan custody bucket by a read-only summary.
            $id=$item['itemId'];
            $item['writtenOffMissing']=(int)($writtenOff[$id]['missing']??0);
            $item['writtenOffDamaged']=(int)($writtenOff[$id]['damage']??0);
            if($item['writtenOffMissing']>$item['unreturned']||
                $item['writtenOffDamaged']>$item['damaged'])
                throw new ConflictHttpException('Physical write-off exceeds independently supported loss evidence.');
            $item['onLoan']=$item['dispatched']-$item['good']-$item['damaged']
                -$item['writtenOffMissing'];
        }
        unset($item);
        $incidentReports=DB::table('hall_inventory_loan_incident_reports')
            ->where('company_id',$companyId)->where('loan_id',$loanId)
            ->where('source_hall_id',$hallId)->count();
        $unreviewed=DB::table('hall_inventory_loan_incident_reports as i')
            ->leftJoin('hall_inventory_loan_incident_decisions as d',function($q):void{
                $q->on('d.company_id','=','i.company_id')->on('d.report_id','=','i.id');
            })
            ->where('i.company_id',$companyId)->where('i.source_hall_id',$hallId)
            ->where('i.loan_id',$loanId)->whereNull('d.id')->count();
        $balances=[];
        if(config('inventory_controls.loan_recoveries_enabled')&&
            config('inventory_controls.loan_authorized_obligations_enabled')&&
            config('inventory_controls.loan_partner_responses_enabled'))
            $balances=app(HallInventoryFinancialRecoveryService::class)
                ->outstanding($actor,$companyId,$hallId,$loanId);
        return ['items'=>array_values($items),'incidentReports'=>(int)$incidentReports,
            'unreviewedIncidents'=>(int)$unreviewed,'obligations'=>$balances,
            'expectedReturnDate'=>(string)$loan->expected_return_date,
            'finalClosureAuthorized'=>false];
    }
}
