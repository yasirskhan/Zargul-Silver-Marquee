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

/** Scoped provisional collection claims; never a payment, receivable, or stock movement. */
final class HallInventoryFinancialClaimService
{
    public function __construct(private readonly TenantAccess $access) {}

    public function propose(User $actor,string $companyId,string $reportId,string $claimId,
        string $currency,int $amountMinor,string $reason):string
    {
        $this->gate();
        if(!Str::isUuid($companyId)||!Str::isUuid($reportId)||!Str::isUuid($claimId)||
            !preg_match('/\A[A-Z]{3}\z/D',$currency)||$amountMinor<1||
            $amountMinor>100000000000||mb_strlen(trim($reason))<10||
            mb_strlen($reason)>500)throw new InvalidArgumentException('Invalid proposed claim.');
        $reason=trim($reason);
        $fingerprint=hash('sha256',json_encode([
            $companyId,$reportId,$currency,$amountMinor,$reason,(int)$actor->getKey()
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$companyId,$reportId,$claimId,
            $currency,$amountMinor,$reason,$fingerprint):string {
            $report=DB::table('hall_inventory_loan_incident_reports')
                ->where('company_id',$companyId)->where('id',$reportId)->first();
            if(!$report)throw new ConflictHttpException('Incident evidence not found.');
            $loan=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$report->loan_id)
                ->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_out'||
                $loan->lending_hall_id!==$report->source_hall_id)
                throw new ConflictHttpException('Original owned external lending is required.');
            $this->authorize($actor,$companyId,(string)$report->source_hall_id);
            $old=DB::table('hall_inventory_financial_claims')
                ->where('company_id',$companyId)->where('report_id',$reportId)->first();
            if($old) {
                if((string)$old->id!==$claimId||
                    (int)$old->proposed_by!==(int)$actor->getKey()||
                    !hash_equals($old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Conflicting financial claim replay.');
                return $claimId;
            }
            if(DB::table('hall_inventory_external_out_clean_closures')
                ->where('company_id',$companyId)->where('loan_id',$loan->id)->exists())
                throw new ConflictHttpException('Clean-closed loan has no loss claim.');
            $review=DB::table('hall_inventory_loan_incident_decisions')
                ->where('company_id',$companyId)->where('report_id',$reportId)
                ->where('decision','substantiated')->first();
            if(!$review)throw new ConflictHttpException('Independent substantiated incident decision required.');
            $this->checkQuantity($companyId,$report,$loan);
            DB::table('hall_inventory_financial_claims')->insert([
                'id'=>$claimId,'company_id'=>$companyId,'loan_id'=>$loan->id,
                'source_hall_id'=>$report->source_hall_id,'report_id'=>$reportId,
                'review_id'=>$review->id,'quantity'=>$report->quantity,
                'currency'=>$currency,'amount_minor'=>$amountMinor,'reason'=>$reason,
                'fingerprint'=>$fingerprint,'proposed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'financial_claim_proposed',$claimId,
                (string)$loan->id,['report'=>$reportId,'amountMinor'=>$amountMinor,
                    'currency'=>$currency,'journalPosted'=>false]);
            return $claimId;
        },3);
    }

    public function decide(User $actor,string $companyId,string $claimId,string $decisionId,
        string $decision,string $reason):string
    {
        $this->gate();
        if(!Str::isUuid($companyId)||!Str::isUuid($claimId)||!Str::isUuid($decisionId)||
            !in_array($decision,['approved_for_collection','rejected','disputed'],true)||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Invalid claim assessment.');
        $reason=trim($reason);
        $fingerprint=hash('sha256',json_encode([
            $companyId,$claimId,$decision,$reason,(int)$actor->getKey()
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$companyId,$claimId,$decisionId,
            $decision,$reason,$fingerprint):string {
            $claim=DB::table('hall_inventory_financial_claims')
                ->where('company_id',$companyId)->where('id',$claimId)
                ->lockForUpdate()->first();
            if(!$claim)throw new ConflictHttpException('Proposed collection assessment not found.');
            $this->authorize($actor,$companyId,(string)$claim->source_hall_id);
            $report=DB::table('hall_inventory_loan_incident_reports')
                ->where('company_id',$companyId)->where('id',$claim->report_id)->first();
            if(!$report||(int)$report->reported_by===(int)$actor->getKey()||
                (int)$claim->proposed_by===(int)$actor->getKey())
                throw new AuthorizationException('An independent owner/admin must decide this claim.');
            $old=DB::table('hall_inventory_financial_claim_decisions')
                ->where('company_id',$companyId)->where('claim_id',$claimId)->first();
            if($old) {
                if((string)$old->id!==$decisionId||
                    (int)$old->decided_by!==(int)$actor->getKey()||
                    !hash_equals($old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Claim already independently decided.');
                return $decisionId;
            }
            DB::table('hall_inventory_financial_claim_decisions')->insert([
                'id'=>$decisionId,'company_id'=>$companyId,'claim_id'=>$claimId,
                'decision'=>$decision,'reason'=>$reason,'fingerprint'=>$fingerprint,
                'decided_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'financial_claim_reviewed',$decisionId,
                (string)$claim->loan_id,['claim'=>$claimId,'decision'=>$decision,
                    'journalPosted'=>false,'partnerAdmitted'=>false,'paidMinor'=>0]);
            return $decisionId;
        },3);
    }

    public function history(User $actor,string $companyId,string $hallId,string $loanId):array
    {
        $this->gate();
        if(!Str::isUuid($loanId))throw new InvalidArgumentException('Invalid loan.');
        $this->authorize($actor,$companyId,$hallId);
        $loan=DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$companyId)->where('id',$loanId)
            ->where('lending_hall_id',$hallId)->where('direction','external_out')->first();
        if(!$loan)throw new AuthorizationException('Other hall claim history is not visible.');
        return DB::table('hall_inventory_financial_claims as c')
            ->leftJoin('hall_inventory_financial_claim_decisions as d',function($j):void {
                $j->on('d.company_id','=','c.company_id')->on('d.claim_id','=','c.id');
            })
            ->where('c.company_id',$companyId)->where('c.source_hall_id',$hallId)
            ->where('c.loan_id',$loanId)->orderByDesc('c.created_at')
            ->orderByDesc('c.id')->limit(100)
            ->get(['c.id','c.report_id','c.quantity','c.amount_minor','c.currency',
                'c.proposed_by','d.decision','d.decided_by'])
            ->map(static fn($x):array=>[
                'id'=>(string)$x->id,'reportId'=>(string)$x->report_id,
                'quantity'=>(int)$x->quantity,'currency'=>(string)$x->currency,
                'amountMinor'=>(int)$x->amount_minor,'proposedBy'=>(int)$x->proposed_by,
                'decision'=>$x->decision,'decidedBy'=>$x->decided_by,
                'paidMinor'=>0,'journalPosted'=>false
            ])->all();
    }

    private function checkQuantity(string $companyId,object $report,object $loan):void
    {
        $dispatch=DB::table('hall_inventory_external_out_dispatches')
            ->where('company_id',$companyId)->where('loan_id',$loan->id)
            ->where('hall_id',$report->source_hall_id)->first();
        $original=$dispatch?DB::table('hall_inventory_external_out_dispatch_lines')
            ->where('company_id',$companyId)->where('dispatch_id',$dispatch->id)
            ->where('item_id',$report->source_item_id)->value('quantity'):null;
        if(!$original)throw new ConflictHttpException('No approved outside-owned physical dispatch.');
        $seen=DB::table('hall_inventory_external_out_return_review_lines as l')
            ->join('hall_inventory_external_out_return_reviews as a',function($j):void {
                $j->on('a.company_id','=','l.company_id')->on('a.id','=','l.review_id');
            })
            ->where('a.company_id',$companyId)->where('a.loan_id',$loan->id)
            ->where('a.hall_id',$report->source_hall_id)
            ->where('l.item_id',$report->source_item_id)
            ->selectRaw('COALESCE(SUM(l.good_quantity),0) as good')
            ->selectRaw('COALESCE(SUM(l.damaged_quantity),0) as damaged')->first();
        $good=(int)($seen->good??0);$damaged=(int)($seen->damaged??0);
        $damage=$report->kind==='damage';
        $maximum=$damage?$damaged:(int)$original-$good-$damaged;
        $kinds=$damage?['damage']:['missing','dispute'];
        // Pending and accepted claims reserve physical units. A claim rejected
        // independently releases its reservation; a disputed claim does not.
        $reserved=DB::table('hall_inventory_financial_claims as c')
            ->join('hall_inventory_loan_incident_reports as r',function($j):void {
                $j->on('r.company_id','=','c.company_id')->on('r.id','=','c.report_id');
            })
            ->leftJoin('hall_inventory_financial_claim_decisions as d',function($j):void {
                $j->on('d.company_id','=','c.company_id')->on('d.claim_id','=','c.id');
            })
            ->where('c.company_id',$companyId)->where('c.loan_id',$loan->id)
            ->where('r.source_item_id',$report->source_item_id)
            ->whereIn('r.kind',$kinds)
            ->where(function($q):void {
                $q->whereNull('d.id')->orWhere('d.decision','!=','rejected');
            })->sum('c.quantity');
        if($maximum<1||(int)$report->quantity>$maximum-(int)$reserved)
            throw new ConflictHttpException('Overlapping claims exceed independently evidenced affected inventory.');
    }

    private function authorize(User $actor,string $companyId,string $hallId):void
    {
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$hallId)->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Current source hall owner/admin required.');
    }

    private function gate():void
    {
        foreach(['loan_financial_claims_enabled','loan_incidents_enabled',
            'loan_incident_reviews_enabled','external_out_dispatch_enabled',
            'external_out_return_enabled'] as $flag)
            if(!config('inventory_controls.'.$flag))
                throw new ConflictHttpException('Outside owned collection assessment is disabled.');
    }

    private function audit(User $actor,string $companyId,string $name,string $id,
        string $loanId,array $details):void
    {
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.'.$name,
            'entity_type'=>'hall_inventory_financial_claim','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($details,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId,'financePosted'=>false],
                JSON_THROW_ON_ERROR)
        ]);
    }

}
