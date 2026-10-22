<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Read-only EXTERNAL_IN non-clean closure readiness. It never writes stock,
 * money or a closure. The future closure must consume this exact assessment.
 */
final class HallInventoryExternalInNoncleanReadinessService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate():void {
        foreach(['loan_drafts_enabled','external_borrowed_intake_enabled',
            'external_borrowed_return_enabled','external_borrowed_discrepancy_enabled',
            'external_in_loss_obligation_enabled','external_in_loss_settlement_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new ConflictHttpException('External-in non-clean closure readiness is not released.');
    }
    public function assess(User $actor,string $companyId,string $hallId,string $loanId):array {
        $this->gate();
        foreach([$companyId,$hallId,$loanId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact readiness UUIDs are required.');
        $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
            ->where('id',$loanId)->first();
        if(!$loan||$loan->direction!=='external_in'||
            (string)$loan->borrowing_hall_id!==$hallId)
            throw new ConflictHttpException('Readiness requires the original borrowing-hall loan.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Current borrowing-hall inventory access required.');

        $itemIds=DB::table('hall_inventory_loan_draft_lines')
            ->where('company_id',$companyId)->where('loan_id',$loanId)->pluck('item_id')->all();
        $position=DB::table('hall_inventory_positions')->where('company_id',$companyId)
            ->where('hall_id',$hallId)->whereIn('item_id',$itemIds)
            ->selectRaw('COALESCE(SUM(borrowed_usable),0) AS usable,
                COALESCE(SUM(borrowed_transit),0) AS transit,
                COALESCE(SUM(borrowed_damaged),0) AS damaged')->first();
        $atHall=(int)($position->usable??0)+(int)($position->damaged??0);
        $inTransit=(int)($position->transit??0);

        $discrepancies=DB::table('hall_inventory_external_in_discrepancies as d')
            ->leftJoin('hall_inventory_external_in_discrepancy_decisions as x',function($j):void {
                $j->on('x.company_id','=','d.company_id')->on('x.discrepancy_id','=','d.id');
            })->where('d.company_id',$companyId)->where('d.loan_id',$loanId)
            ->get(['d.id','x.decision']);
        $pendingDiscrepancies=0;$substantiated=[];
        foreach($discrepancies as $disc) {
            if($disc->decision===null||$disc->decision==='requires_more_evidence')
                $pendingDiscrepancies++;
            elseif($disc->decision==='substantiated')$substantiated[]=(string)$disc->id;
        }
        $partnerMissing=0;$partnerDamaged=0;
        if($substantiated!==[]) {
            $lines=DB::table('hall_inventory_external_in_discrepancy_lines')
                ->where('company_id',$companyId)->whereIn('discrepancy_id',$substantiated)
                ->get(['shipped_quantity','received_quantity','damaged_quantity']);
            foreach($lines as $line) {
                $partnerMissing+=max(0,(int)$line->shipped_quantity-(int)$line->received_quantity);
                $partnerDamaged+=(int)$line->damaged_quantity;
            }
        }

        $obligations=DB::table('hall_inventory_external_in_loss_obligations as o')
            ->leftJoin('hall_inventory_external_in_loss_obligation_decisions as od',function($j):void {
                $j->on('od.company_id','=','o.company_id')->on('od.obligation_id','=','o.id');
            })->leftJoin('hall_inventory_external_in_loss_settlements as s',function($j):void {
                $j->on('s.company_id','=','o.company_id')->on('s.obligation_id','=','o.id');
            })->leftJoin('hall_inventory_external_in_loss_settlement_reviews as r',function($j):void {
                $j->on('r.company_id','=','s.company_id')->on('r.settlement_id','=','s.id');
            })->leftJoin('hall_inventory_external_in_loss_settlement_reversals as v',function($j):void {
                $j->on('v.company_id','=','s.company_id')->on('v.settlement_id','=','s.id');
            })->where('o.company_id',$companyId)->where('o.loan_id',$loanId)
            ->get(['o.id','o.discrepancy_id','o.amount_minor','o.currency',
                'od.decision as obligation_decision','s.id as settlement_id',
                's.amount_minor as settlement_amount','r.decision as settlement_decision',
                'r.journal_id','v.id as reversal_id']);
        $approvedDiscrepancies=[];$unsettled=0;$ledgerPosted=true;$settled=0;$pendingObligations=0;
        foreach($obligations as $obligation) {
            if($obligation->obligation_decision!=='approved') {
                $pendingObligations++;
                continue;
            }
            $approvedDiscrepancies[]=(string)$obligation->discrepancy_id;
            $isSettled=$obligation->settlement_id!==null &&
                $obligation->settlement_decision==='verified' &&
                $obligation->reversal_id===null &&
                (int)$obligation->settlement_amount>=(int)$obligation->amount_minor;
            if(!$isSettled) {
                $unsettled++;
                continue;
            }
            $settled++;
            if($obligation->journal_id===null)$ledgerPosted=false;
        }
        $missingObligations=0;
        foreach($substantiated as $discrepancyId)
            if(!in_array($discrepancyId,$approvedDiscrepancies,true))$missingObligations++;

        $postingEnabled=config('finance_controls.posting_enabled')===true;
        $ledgerReady=$postingEnabled && $settled>0 && $ledgerPosted &&
            $unsettled===0 && $pendingObligations===0 && $missingObligations===0;
        $blockers=[];
        if($atHall>0)$blockers[]='Physical borrowed units remain in the hall.';
        if($inTransit!==$partnerMissing)
            $blockers[]='Borrowed transit does not equal the independently substantiated missing quantity.';
        if($pendingDiscrepancies>0)$blockers[]='Partner discrepancy documentary review is pending.';
        if($pendingObligations>0)$blockers[]='A partner loss obligation is not independently approved.';
        if($unsettled>0)$blockers[]='A partner loss obligation is not fully settled or was reversed.';
        if($missingObligations>0)$blockers[]='A substantiated discrepancy has no approved obligation.';
        if(!$postingEnabled)$blockers[]='Finance posting is OFF; the verified financial position cannot be certified.';
        elseif(!$ledgerPosted||$settled===0)
            $blockers[]='The verified partner payment is not fully posted to the balanced ledger.';
        return [
            'loanId'=>$loanId,'physicalUnitsAtHall'=>$atHall,
            'missingInTransit'=>$inTransit,'partnerReportedMissing'=>$partnerMissing,
            'partnerReportedDamaged'=>$partnerDamaged,
            'pendingDiscrepancies'=>$pendingDiscrepancies,
            'pendingObligations'=>$pendingObligations,'unsettledObligations'=>$unsettled,
            'settledObligations'=>$settled,'missingObligations'=>$missingObligations,
            'financePostingEnabled'=>$postingEnabled,'verifiedLedgerPosted'=>$ledgerReady,
            'ready'=>$blockers===[]&&$ledgerReady,'blockers'=>$blockers,
            'stockWrittenOff'=>false,'moneyMoved'=>false,'loanClosed'=>false,
        ];
    }
}
