<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * Read-only INTERNAL abnormal closure readiness. It never posts money,
 * retires stock, writes off custody or closes the loan. The accounting/ledger
 * and authorized write-off decisions remain explicit blockers.
 */
final class HallInventoryInternalAbnormalReadinessService
{
    public function __construct(private readonly TenantAccess $access) {}
    private function gate():void {
        foreach(['loan_drafts_enabled','loan_returns_enabled','loan_return_acceptance_enabled',
            'internal_discrepancy_enabled','internal_physical_finding_enabled',
            'internal_loss_obligation_enabled','internal_loss_settlement_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                    'Internal abnormal closure readiness is not released.');
    }
    public function assess(User $actor,string $companyId,string $sourceHallId,string $loanId):array {
        $this->gate();
        foreach([$companyId,$sourceHallId,$loanId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact internal readiness UUIDs required.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Internal readiness is source-hall restricted.');
        $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
            ->where('id',$loanId)->first();
        if(!$loan||$loan->direction!=='internal'||(string)$loan->lending_hall_id!==$sourceHallId)
            throw new AuthorizationException('Readiness requires the source-hall internal loan.');
        $findings=app(HallInventoryInternalPhysicalFindingService::class)
            ->history($actor,$companyId,$sourceHallId,$loanId,true);
        $confirmed=array_values(array_filter($findings,static fn($f):bool=>
            ($f['decision']??null)==='confirmed_outstanding'));
        $physical=(int)array_sum(array_map(static fn($f):int=>
            (int)($f['currentPhysicalOutstanding']??0),$confirmed));
        $obligations=DB::table('hall_inventory_internal_loss_obligations as o')
            ->join('hall_inventory_internal_loss_obligation_decisions as d',function($j):void {
                $j->on('d.company_id','=','o.company_id')->on('d.obligation_id','=','o.id');
            })->where('o.company_id',$companyId)->where('o.loan_id',$loanId)
            ->where('d.decision','approved')->get(['o.id','o.currency','o.amount_minor']);
        $settlements=DB::table('hall_inventory_internal_loss_settlements as s')
            ->join('hall_inventory_internal_loss_settlement_reviews as r',function($j):void {
                $j->on('r.company_id','=','s.company_id')->on('r.settlement_id','=','s.id');
            })->where('s.company_id',$companyId)->whereIn('s.obligation_id',$obligations->pluck('id'))
            ->where('r.decision','verified')->count();
        $currencies=array_values(array_unique($obligations->pluck('currency')->all()));
        $blockers=[];
        if($confirmed===[]) $blockers[]='No independently confirmed physical finding.';
        if($physical>0) $blockers[]=$physical.' owned-on-loan unit(s) remain physically open; authorized write-off is not released.';
        if($obligations->isEmpty()) $blockers[]='No independently approved internal loss obligation.';
        if(count($currencies)>1) $blockers[]='Approved internal obligations use more than one currency.';
        if(!$obligations->isEmpty()&&$settlements===0)
            $blockers[]='No independently verified internal settlement evidence.';
        $blockers[]='Accounting/owner decision for the inter-hall settlement direction and ledger accounts is pending; no balanced journal is posted.';
        $blockers[]='Authorized physical write-off and numbered abnormal final certificate are not released.';
        return ['loanId'=>$loanId,'sourceHallId'=>$sourceHallId,
            'confirmedFindings'=>count($confirmed),'physicalUnitsOpen'=>$physical,
            'approvedObligations'=>$obligations->count(),
            'verifiedSettlements'=>$settlements,
            'obligationCurrencies'=>$currencies,
            'obligationAmountMinor'=>(int)$obligations->sum('amount_minor'),
            'ledgerPosted'=>false,'writeOffReleased'=>false,'certificateIssued'=>false,
            'ready'=>false,'blockers'=>$blockers];
    }
}
