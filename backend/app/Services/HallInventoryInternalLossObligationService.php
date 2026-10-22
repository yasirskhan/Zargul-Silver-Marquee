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
 * Provisional INTERNAL abnormal loss obligation and independent decision.
 * Confirmed physical finding only. Never a journal, cash movement, waiver,
 * stock retirement or loan closure.
 */
final class HallInventoryInternalLossObligationService
{
    public function __construct(private readonly TenantAccess $access) {}
    private function gate():void {
        foreach(['loan_drafts_enabled','loan_returns_enabled','loan_return_acceptance_enabled',
            'internal_discrepancy_enabled','internal_physical_finding_enabled',
            'internal_loss_obligation_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new ConflictHttpException('Internal abnormal loss obligations are not released.');
    }
    public function propose(User $actor,string $companyId,string $sourceHallId,string $findingId,
        string $obligationId,string $currency,int $amountMinor,string $reason):string {
        $this->gate();
        foreach([$companyId,$sourceHallId,$findingId,$obligationId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact internal obligation UUIDs required.');
        if(!preg_match('/\A[A-Z]{3}\z/D',$currency)||$amountMinor<1||$amountMinor>100000000000||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Invalid internal abnormal loss obligation.');
        $reason=trim($reason);
        return DB::transaction(function()use($actor,$companyId,$sourceHallId,$findingId,
            $obligationId,$currency,$amountMinor,$reason):string {
            $finding=DB::table('hall_inventory_internal_physical_findings')
                ->where('company_id',$companyId)->where('source_hall_id',$sourceHallId)
                ->where('id',$findingId)->lockForUpdate()->first();
            if(!$finding)throw new AuthorizationException('Finding is outside this source hall.');
            $decision=DB::table('hall_inventory_internal_physical_finding_decisions')
                ->where('company_id',$companyId)->where('finding_id',$findingId)->first();
            if(!$decision||$decision->decision!=='confirmed_outstanding')
                throw new ConflictHttpException('Only an independently confirmed physical finding may ground an obligation.');
            if(DB::table('hall_inventory_internal_finding_corrections')
                ->where('company_id',$companyId)->where('finding_id',$findingId)->exists())
                throw new ConflictHttpException('A superseding signed correction prevents a new pricing obligation.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current source-hall owner/admin required.');
            $fingerprint=hash('sha256',json_encode([$companyId,$sourceHallId,$findingId,
                (string)$decision->id,(int)$finding->quantity,$currency,$amountMinor,$reason,
                (int)$actor->getKey()],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_internal_loss_obligations')
                ->where('company_id',$companyId)->where('finding_id',$findingId)->first();
            if($old) {
                if((string)$old->id!==$obligationId||
                    (int)$old->proposed_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Internal loss obligation already has different immutable values.');
                return $obligationId;
            }
            DB::table('hall_inventory_internal_loss_obligations')->insert([
                'id'=>$obligationId,'company_id'=>$companyId,'loan_id'=>$finding->loan_id,
                'source_hall_id'=>$sourceHallId,'borrowing_hall_id'=>$finding->borrowing_hall_id,
                'finding_id'=>$findingId,'decision_id'=>$decision->id,
                'quantity_basis'=>(int)$finding->quantity,'currency'=>$currency,
                'amount_minor'=>$amountMinor,'reason'=>$reason,'fingerprint'=>$fingerprint,
                'proposed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'proposed',$obligationId,(string)$finding->loan_id,
                ['findingId'=>$findingId,'quantityBasis'=>(int)$finding->quantity,
                 'currency'=>$currency,'amountMinor'=>$amountMinor,
                 'journalPosted'=>false,'stockRetired'=>false,'loanClosed'=>false]);
            return $obligationId;
        },3);
    }
    public function decide(User $actor,string $companyId,string $sourceHallId,string $obligationId,
        string $decisionId,string $decision,string $reason):string {
        $this->gate();
        foreach([$companyId,$sourceHallId,$obligationId,$decisionId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact internal obligation decision UUIDs required.');
        if(!in_array($decision,['approved','rejected','disputed'],true)||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independent internal obligation decision required.');
        $reason=trim($reason);
        return DB::transaction(function()use($actor,$companyId,$sourceHallId,$obligationId,
            $decisionId,$decision,$reason):string {
            $obligation=DB::table('hall_inventory_internal_loss_obligations')
                ->where('company_id',$companyId)->where('source_hall_id',$sourceHallId)
                ->where('id',$obligationId)->lockForUpdate()->first();
            if(!$obligation)throw new AuthorizationException('Obligation is outside this source hall.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current source-hall owner/admin required.');
            if((int)$obligation->proposed_by===(int)$actor->getKey())
                throw new AuthorizationException('Obligation proposer cannot decide their own amount.');
            $finding=DB::table('hall_inventory_internal_physical_findings')
                ->where('company_id',$companyId)->where('id',$obligation->finding_id)->first();
            if(!$finding||(int)$finding->proposed_by===(int)$actor->getKey())
                throw new AuthorizationException('Finding proposer cannot approve the internal obligation.');
            $findingDecision=DB::table('hall_inventory_internal_physical_finding_decisions')
                ->where('company_id',$companyId)->where('finding_id',$obligation->finding_id)->first();
            if($findingDecision && (int)$findingDecision->decided_by===(int)$actor->getKey())
                throw new AuthorizationException('Finding reviewer cannot approve the internal obligation amount.');
            $fingerprint=hash('sha256',json_encode([$companyId,$sourceHallId,$obligationId,
                (int)$actor->getKey(),$decision,$reason],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_internal_loss_obligation_decisions')
                ->where('company_id',$companyId)->where('obligation_id',$obligationId)->first();
            if($old) {
                if((string)$old->id!==$decisionId||
                    (int)$old->decided_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Internal loss obligation already has an immutable decision.');
                return $decisionId;
            }
            DB::table('hall_inventory_internal_loss_obligation_decisions')->insert([
                'id'=>$decisionId,'company_id'=>$companyId,'obligation_id'=>$obligationId,
                'decision'=>$decision,'reason'=>$reason,'fingerprint'=>$fingerprint,
                'decided_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'reviewed',$decisionId,(string)$obligation->loan_id,
                ['obligationId'=>$obligationId,'decision'=>$decision,
                 'journalPosted'=>false,'stockRetired'=>false,'loanClosed'=>false]);
            return $decisionId;
        },3);
    }
    public function recent(User $actor,string $companyId,string $sourceHallId):array {
        $this->gate();
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Internal obligation history is source-hall restricted.');
        return DB::table('hall_inventory_internal_loss_obligations as o')
            ->leftJoin('hall_inventory_internal_loss_obligation_decisions as d',function($j):void {
                $j->on('d.company_id','=','o.company_id')->on('d.obligation_id','=','o.id');
            })->where('o.company_id',$companyId)->where('o.source_hall_id',$sourceHallId)
            ->orderByDesc('o.created_at')->orderByDesc('o.id')->limit(100)
            ->get(['o.id','o.finding_id','o.quantity_basis','o.currency','o.amount_minor',
                'o.proposed_by','d.decision','d.decided_by'])
            ->map(static fn($x):array=>['id'=>(string)$x->id,'findingId'=>(string)$x->finding_id,
                'quantityBasis'=>(int)$x->quantity_basis,'currency'=>(string)$x->currency,
                'amountMinor'=>(int)$x->amount_minor,'proposedBy'=>(int)$x->proposed_by,
                'decision'=>$x->decision,'decidedBy'=>$x->decided_by,
                'journalPosted'=>false,'stockRetired'=>false,'loanClosed'=>false])->all();
    }
    private function audit(User $actor,string $companyId,string $action,string $id,
        string $loanId,array $data):void {
        DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.internal_loss_obligation_'.$action,
            'entity_type'=>'hall_inventory_internal_loss_obligation','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId],JSON_THROW_ON_ERROR)]);
    }
}
