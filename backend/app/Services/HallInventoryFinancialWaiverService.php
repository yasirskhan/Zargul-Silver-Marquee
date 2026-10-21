<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use App\Policies\FinanceAccessPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Owner-authorized contractual forgiveness of PART of an independently approved
 * obligation. It is not proof of payment, a received journal entry, partner
 * admission, physical stock movement or a final loan receipt. The vendor
 * cash-basis journal has no accrued receivable to reverse.
 */
final class HallInventoryFinancialWaiverService {
    public function __construct(private readonly TenantAccess $access) {}
    private function gate():void {
        foreach(['loan_financial_waivers_enabled','loan_recoveries_enabled',
            'loan_authorized_obligations_enabled','loan_partner_responses_enabled',
            'loan_financial_claims_enabled'] as $flag)
            if(config('inventory_controls.'.$flag)!==true)
                throw new ConflictHttpException('Independent inventory financial waiver not released.');
    }
    private function ids(string ...$ids):void {
        foreach($ids as $id)if(!Str::isUuid($id))
            throw new InvalidArgumentException('Exact financial waiver UUID required.');
    }
    private function actor(User $actor,string $companyId,string $hallId):void {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall)||
            !(new FinanceAccessPolicy())->post($actor,$companyId))
            throw new AuthorizationException('Current lending hall owner with finance authorization required.');
    }
    private function obligation(string $companyId,string $hallId,string $obligationId):object {
        $row=DB::table('hall_inventory_financial_obligations as o')
            ->join('hall_inventory_financial_obligation_decisions as d',function($j):void {
                $j->on('d.company_id','=','o.company_id')->on('d.obligation_id','=','o.id');
            })->where('o.company_id',$companyId)->where('o.source_hall_id',$hallId)
            ->where('o.id',$obligationId)->where('d.decision','authorized')
            ->first(['o.id','o.claim_id','o.loan_id','o.source_hall_id','o.currency',
                'o.amount_minor','o.proposed_by','d.decided_by']);
        if(!$row)throw new ConflictHttpException('Only independently authorized exact-hall obligations may be waived.');
        return $row;
    }
    private function reservation(string $companyId,string $obligationId,?string $excludeId=null):array {
        $q=DB::table('hall_inventory_financial_waiver_proposals as p')
            ->leftJoin('hall_inventory_financial_waiver_decisions as d',function($j):void {
                $j->on('d.company_id','=','p.company_id')->on('d.proposal_id','=','p.id');
            })->where('p.company_id',$companyId)->where('p.obligation_id',$obligationId);
        if($excludeId)$q->where('p.id','!=',$excludeId);
        $rows=$q->get(['p.amount_minor','d.decision']);
        $approved=0;$pending=0;
        foreach($rows as $row) {
            if($row->decision==='approved')$approved+=(int)$row->amount_minor;
            elseif($row->decision===null)$pending+=(int)$row->amount_minor;
        }
        return ['approved'=>$approved,'pending'=>$pending];
    }
    public function totals(string $companyId,string $hallId,string $loanId):array {
        // Internal calculation only; caller MUST independently authorize loan scope.
        $rows=DB::table('hall_inventory_financial_waiver_proposals as p')
            ->leftJoin('hall_inventory_financial_waiver_decisions as d',function($j):void {
                $j->on('d.company_id','=','p.company_id')->on('d.proposal_id','=','p.id');
            })->where('p.company_id',$companyId)->where('p.hall_id',$hallId)
            ->where('p.loan_id',$loanId)
            ->get(['p.obligation_id','p.amount_minor','d.decision']);
        $out=[];
        foreach($rows as $r) {
            $id=(string)$r->obligation_id;
            $out[$id]??=['approved'=>0,'pending'=>0];
            if($r->decision==='approved')$out[$id]['approved']+=(int)$r->amount_minor;
            elseif($r->decision===null)$out[$id]['pending']+=(int)$r->amount_minor;
        }
        return $out;
    }
    public function propose(User $actor,string $companyId,string $hallId,string $obligationId,
        string $proposalId,int $amountMinor,string $reason):string {
        $this->gate();$this->ids($companyId,$hallId,$obligationId,$proposalId);
        $reason=trim($reason);
        if($amountMinor<1||$amountMinor>100000000000||mb_strlen($reason)<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Exact partial waiver amount and documented decision reason required.');
        $this->actor($actor,$companyId,$hallId);
        return DB::transaction(function()use($actor,$companyId,$hallId,$obligationId,$proposalId,$amountMinor,$reason):string {
            $company=DB::table('companies')->where('id',$companyId)->where('is_active',true)
                ->lockForUpdate()->first();
            if(!$company)throw new AuthorizationException('Inactive company cannot waive obligations.');
            $obligation=$this->obligation($companyId,$hallId,$obligationId);
            if(DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$companyId)
                ->where('loan_id',$obligation->loan_id)->exists())
                throw new ConflictHttpException('Final non-clean financial settlement is immutable.');

            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$obligationId,
                (int)$actor->getKey(),$amountMinor,$reason],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_financial_waiver_proposals')
                ->where('company_id',$companyId)->where('id',$proposalId)->first();
            if($old) {
                if((string)$old->obligation_id!==$obligationId||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Conflicting original financial waiver request replay.');
                return $proposalId;
            }
            $balance=app(HallInventoryFinancialRecoveryService::class)
                ->outstanding($actor,$companyId,$hallId,$obligation->loan_id);
            $money=$balance[$obligationId]??null;
            if(!$money)throw new ConflictHttpException('Original collectible obligation balance not found.');
            // outstanding() already subtracts posted and pending waivers exactly once.
            $available=(int)$money['unreservedMinor'];
            if($amountMinor>$available)
                throw new ConflictHttpException('Unverified or already reserved debt cannot be waived twice.');
            DB::table('hall_inventory_financial_waiver_proposals')->insert([
                'id'=>$proposalId,'company_id'=>$companyId,'obligation_id'=>$obligationId,
                'claim_id'=>$obligation->claim_id,'loan_id'=>$obligation->loan_id,
                'hall_id'=>$hallId,'currency'=>$obligation->currency,'amount_minor'=>$amountMinor,
                'reason'=>$reason,'fingerprint'=>$fingerprint,'proposed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'waiver_proposed',$proposalId,$obligation->loan_id,
                ['obligationId'=>$obligationId,'amountMinor'=>$amountMinor,
                 'currency'=>$obligation->currency,'collectedCash'=>false]);
            return $proposalId;
        },3);
    }
    public function decide(User $actor,string $companyId,string $hallId,string $proposalId,
        string $decisionId,string $decision,string $reason):string {
        $this->gate();$this->ids($companyId,$hallId,$proposalId,$decisionId);
        $reason=trim($reason);
        if(!in_array($decision,['approved','rejected'],true)||mb_strlen($reason)<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independent financial waiver review reason and outcome required.');
        $this->actor($actor,$companyId,$hallId);
        return DB::transaction(function()use($actor,$companyId,$hallId,$proposalId,$decisionId,$decision,$reason):string {
            $company=DB::table('companies')->where('id',$companyId)->where('is_active',true)
                ->lockForUpdate()->first();
            if(!$company)throw new AuthorizationException('Inactive company.');
            $proposal=DB::table('hall_inventory_financial_waiver_proposals')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$proposalId)->lockForUpdate()->first();
            if(!$proposal)throw new AuthorizationException('Other hall financial waiver is private.');
            if((int)$proposal->proposed_by===(int)$actor->getKey())
                throw new AuthorizationException('Financial waiver proposer cannot approve self.');
            $obligation=$this->obligation($companyId,$hallId,$proposal->obligation_id);
            if(DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$companyId)
                ->where('loan_id',$obligation->loan_id)->exists())
                throw new ConflictHttpException('Final non-clean financial settlement is immutable.');

            if((string)$obligation->loan_id!==(string)$proposal->loan_id||
                (string)$obligation->currency!==(string)$proposal->currency||
                (string)$obligation->claim_id!==(string)$proposal->claim_id)
                throw new ConflictHttpException('Original claim and waiver currency provenance mismatch.');
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$proposalId,
                (int)$actor->getKey(),$decision,$reason],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_financial_waiver_decisions')
                ->where('company_id',$companyId)->where('proposal_id',$proposalId)->first();
            if($old) {
                if($old->id!==$decisionId||!hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Existing immutable waiver decision cannot be replaced.');
                return $decisionId;
            }
            if($decision==='approved') {
                $balance=app(HallInventoryFinancialRecoveryService::class)
                    ->outstanding($actor,$companyId,$hallId,$proposal->loan_id);
                $money=$balance[$proposal->obligation_id]??null;
                if(!$money)throw new ConflictHttpException('Financial source balance disappeared.');
                // Add back only this immutable pending reservation during final review.
                $available=(int)$money['unreservedMinor']+(int)$proposal->amount_minor;
                if((int)$proposal->amount_minor>$available)
                    throw new ConflictHttpException('Concurrent collections or other waivers consumed this balance.');
            }
            DB::table('hall_inventory_financial_waiver_decisions')->insert([
                'id'=>$decisionId,'company_id'=>$companyId,'proposal_id'=>$proposalId,
                'obligation_id'=>$proposal->obligation_id,'decision'=>$decision,
                'reason'=>$reason,'fingerprint'=>$fingerprint,'decided_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'waiver_decided',$decisionId,$proposal->loan_id,
                ['proposalId'=>$proposalId,'decision'=>$decision,
                 'amountMinor'=>$proposal->amount_minor,'currency'=>$proposal->currency,
                 'cashPosting'=>false,'physicalStockWriteoff'=>false,'loanClosure'=>false]);
            return $decisionId;
        },3);
    }
    public function history(User $actor,string $companyId,string $hallId,string $loanId):array {
        $this->gate();$this->ids($companyId,$hallId,$loanId);$this->actor($actor,$companyId,$hallId);
        if(!DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
            ->where('lending_hall_id',$hallId)->where('id',$loanId)
            ->where('direction','external_out')->exists())
            throw new AuthorizationException('Other hall waiver history is private.');
        return DB::table('hall_inventory_financial_waiver_proposals as p')
            ->leftJoin('hall_inventory_financial_waiver_decisions as d',function($j):void {
                $j->on('d.company_id','=','p.company_id')->on('d.proposal_id','=','p.id');
            })->where('p.company_id',$companyId)->where('p.hall_id',$hallId)
            ->where('p.loan_id',$loanId)->orderByDesc('p.created_at')->limit(200)
            ->get(['p.id','p.obligation_id','p.amount_minor','p.currency','p.reason',
                'p.proposed_by','d.decision','d.decided_by'])
            ->map(static fn($r):array=>['id'=>$r->id,'obligationId'=>$r->obligation_id,
                'amountMinor'=>(int)$r->amount_minor,'currency'=>(string)$r->currency,
                'reason'=>(string)$r->reason,'proposedBy'=>(int)$r->proposed_by,
                'decision'=>$r->decision,'decidedBy'=>$r->decided_by])->all();
    }
    private function audit(User $actor,string $companyId,string $name,string $id,string $loanId,array $fields):void {
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.financial_'.$name,
            'entity_type'=>'hall_inventory_financial_waiver','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($fields,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId,'physicalCustodyUnchanged'=>true],JSON_THROW_ON_ERROR)
        ]);
    }
}