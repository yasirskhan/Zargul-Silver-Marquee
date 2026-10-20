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
 * Independent documentary review of an inventory incident.
 * Never determines monetary liability, posts a journal, moves stock or
 * resolves final loan custody. Every review is immutable and single-use.
 */
final class HallInventoryLoanIncidentDecisionService
{
    public function __construct(private readonly TenantAccess $access) {}

    public function decide(User $actor, string $companyId, string $reportId,
        string $decisionId, string $decision, string $reason):string
    {
        $this->gated();
        if (!Str::isUuid($companyId)||!Str::isUuid($reportId)||
            !Str::isUuid($decisionId)||
            !in_array($decision,['substantiated','unsubstantiated','requires_more_evidence'],true)||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Invalid inventory incident review.');
        $reason=trim($reason);
        $fingerprint=hash('sha256',json_encode([
            $companyId,$reportId,(int)$actor->getKey(),$decision,$reason
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$companyId,$reportId,
            $decisionId,$decision,$reason,$fingerprint):string {
            $report=DB::table('hall_inventory_loan_incident_reports')
                ->where('company_id',$companyId)->where('id',$reportId)
                ->lockForUpdate()->first();
            if(!$report)throw new ConflictHttpException('Incident report was not found.');
            $hall=Hall::query()->where('company_id',$companyId)
                ->where('id',$report->source_hall_id)->where('is_active',true)->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                (int)$report->reported_by===(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Independent source owner/admin review required.');
            $existing=DB::table('hall_inventory_loan_incident_decisions')
                ->where('company_id',$companyId)->where('report_id',$reportId)->first();
            if($existing) {
                if((string)$existing->id!==$decisionId||
                    (int)$existing->reviewed_by!==(int)$actor->getKey()||
                    !hash_equals((string)$existing->decision_fingerprint,$fingerprint))
                    throw new ConflictHttpException('Incident was already independently reviewed.');
                return $decisionId;
            }
            DB::table('hall_inventory_loan_incident_decisions')->insert([
                'id'=>$decisionId,'company_id'=>$companyId,'report_id'=>$reportId,
                'decision'=>$decision,'reason'=>$reason,
                'decision_fingerprint'=>$fingerprint,'reviewed_by'=>$actor->getKey()
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$actor->getKey(),
                'action'=>'hall_inventory.internal_loan_incident_reviewed',
                'entity_type'=>'hall_inventory_loan_incident_decision',
                'entity_id'=>$decisionId,
                'before_values'=>json_encode(['incidentReportId'=>$reportId,'decision'=>null],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['incidentReportId'=>$reportId,'decision'=>$decision],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['sourceHallId'=>$report->source_hall_id,
                    'loanId'=>$report->loan_id,'financialLiabilityApproved'=>false,
                    'custodyCompleted'=>false],JSON_THROW_ON_ERROR)
            ]);
            return $decisionId;
        },3);
    }

    public function reviews(User $actor,string $companyId,string $hallId,string $loanId):array
    {
        $this->gated();
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||!Str::isUuid($loanId))
            throw new InvalidArgumentException('Invalid incident review scope.');
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$hallId)->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Inventory review is restricted to a current source owner/admin.');
        return DB::table('hall_inventory_loan_incident_reports as r')
            ->leftJoin('hall_inventory_loan_incident_decisions as d',function($j):void {
                $j->on('d.company_id','=','r.company_id')
                    ->on('d.report_id','=','r.id');
            })
            ->where('r.company_id',$companyId)->where('r.source_hall_id',$hallId)
            ->where('r.loan_id',$loanId)
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->limit(100)
            ->get(['r.id','r.source_item_id','r.kind','r.quantity','r.reason',
                'r.reported_by','d.id as decision_id','d.decision','d.reason as review_reason',
                'd.reviewed_by'])
            ->map(static fn($r):array=>[
                'reportId'=>(string)$r->id,'itemId'=>(string)$r->source_item_id,
                'kind'=>(string)$r->kind,'quantity'=>(int)$r->quantity,
                'reportedReason'=>(string)$r->reason,'reportedBy'=>(int)$r->reported_by,
                'decisionId'=>$r->decision_id,'decision'=>$r->decision,
                'reviewReason'=>$r->review_reason,'reviewedBy'=>$r->reviewed_by,
                'monetaryObligationSettled'=>false,'loanClosed'=>false
            ])->all();
    }

    private function gated():void
    {
        if(!config('inventory_controls.loan_incidents_enabled')||
            !config('inventory_controls.loan_incident_reviews_enabled'))
            throw new ConflictHttpException('Independent loan incident review is disabled.');
    }
}
