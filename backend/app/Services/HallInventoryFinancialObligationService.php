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
 * Two-stage, manually attested outside partner obligation authorization.
 * An approval is a custody/collection instruction, NOT a journaled receivable,
 * paid collection, settled dispute, inventory write-off, or final loan closure.
 */
final class HallInventoryFinancialObligationService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate():void
    {
        foreach(['loan_financial_claims_enabled','loan_partner_responses_enabled',
            'loan_authorized_obligations_enabled','loan_incidents_enabled',
            'loan_incident_reviews_enabled','external_out_dispatch_enabled',
            'external_out_return_enabled'] as $flag)
            if(!config('inventory_controls.'.$flag))
                throw new ConflictHttpException('Financial obligation authorization workflow is disabled.');
    }

    private function ids(string ...$ids):void
    {
        foreach($ids as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact monetary obligation UUID required.');
    }

    private function authorize(User $actor,string $companyId,string $hallId):void
    {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Current original lending hall owner/admin required.');
    }

    private function verifyDocument(object $doc):void
    {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$doc->evidence_path))
            throw new ConflictHttpException('Claimed signed agreement document is missing.');
        $bytes=$disk->get((string)$doc->evidence_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$doc->evidence_size||
            !hash_equals((string)$doc->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Agreement evidence hash or size mismatch.');
    }

    public function propose(User $actor,string $companyId,string $claimId,
        string $evidenceId,string $obligationId,int $amountMinor,
        string $identityCheckMethod,string $identityCheckReference,string $reason):string
    {
        $this->gate();$this->ids($companyId,$claimId,$evidenceId,$obligationId);
        $reason=trim($reason);$identityCheckReference=trim($identityCheckReference);
        if(!in_array($identityCheckMethod,['verified_callback','in_person_signed','trusted_contract_record'],true)||
            !preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._\/#:-]{11,199}\z/D',$identityCheckReference)||
            mb_strlen($reason)<10||mb_strlen($reason)>500||
            $amountMinor<1||$amountMinor>100000000000)
            throw new InvalidArgumentException('A bounded amount and independently checkable partner identification reference are required.');
        $fingerprint=hash('sha256',json_encode([$companyId,$claimId,$evidenceId,$amountMinor,
            $identityCheckMethod,$identityCheckReference,$reason,(int)$actor->getKey()],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$companyId,$claimId,$evidenceId,$obligationId,
            $amountMinor,$identityCheckMethod,$identityCheckReference,$reason,$fingerprint):string {
            $claim=DB::table('hall_inventory_financial_claims')
                ->where('company_id',$companyId)->where('id',$claimId)
                ->lockForUpdate()->first();
            if(!$claim)throw new ConflictHttpException('Original external financial claim not found.');
            $this->authorize($actor,$companyId,(string)$claim->source_hall_id);
            $assessment=DB::table('hall_inventory_financial_claim_decisions')
                ->where('company_id',$companyId)->where('claim_id',$claimId)->first();
            if(!$assessment||$assessment->decision!=='approved_for_collection')
                throw new ConflictHttpException('Disputed, rejected or unreviewed claims cannot be authorized obligations.');
            $doc=DB::table('hall_inventory_financial_counterparty_evidence')
                ->where('company_id',$companyId)->where('claim_id',$claimId)
                ->where('source_hall_id',$claim->source_hall_id)
                ->where('loan_id',$claim->loan_id)->where('currency',$claim->currency)
                ->where('id',$evidenceId)->first();
            if(!$doc || !in_array($doc->position,['document_says_accepts','counteroffer'],true))
                throw new ConflictHttpException('Partner acceptance/counteroffer document required.');
            $review=DB::table('hall_inventory_financial_counterparty_reviews')
                ->where('company_id',$companyId)->where('evidence_id',$evidenceId)
                ->where('assessment','document_integrity_confirmed')->first();
            if(!$review)throw new ConflictHttpException('Independent document custody review required.');
            $this->verifyDocument($doc);
            if(($doc->position==='document_says_accepts' &&
                 $amountMinor!==(int)$claim->amount_minor) ||
               ($doc->position==='counteroffer' && (
                 $amountMinor>(int)$doc->counteroffer_minor ||
                 $amountMinor>(int)$claim->amount_minor)))
                throw new ConflictHttpException('Agreement amount exceeds or mismatches evidenced offer.');
            $old=DB::table('hall_inventory_financial_obligations')
                ->where('company_id',$companyId)->where('evidence_id',$evidenceId)
                ->where('claim_id',$claimId)->first();
            if($old) {
                if((string)$old->id!==$obligationId||
                    (int)$old->proposed_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Agreement already proposed or conflicting replay.');
                return $obligationId;
            }
            if(DB::table('hall_inventory_financial_obligation_decisions')
                ->where('company_id',$companyId)->where('claim_id',$claimId)
                ->where('decision','authorized')->exists())
                throw new ConflictHttpException('This claim already has a separately authorized agreement.');
            DB::table('hall_inventory_financial_obligations')->insert([
                'id'=>$obligationId,'company_id'=>$companyId,'claim_id'=>$claimId,
                'loan_id'=>$claim->loan_id,'source_hall_id'=>$claim->source_hall_id,
                'currency'=>$claim->currency,'evidence_id'=>$evidenceId,
                'amount_minor'=>$amountMinor,'identity_check_method'=>$identityCheckMethod,
                'identity_check_reference'=>$identityCheckReference,
                'reason'=>$reason,'fingerprint'=>$fingerprint,'proposed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'manual_agreement_proposed',$obligationId,
                (string)$claim->loan_id,['claimId'=>$claimId,'evidenceId'=>$evidenceId,
                    'currency'=>$claim->currency,'amountMinor'=>$amountMinor,
                    'identityVerificationMethod'=>$identityCheckMethod,'journalPosted'=>false]);
            return $obligationId;
        },3);
    }

    public function decide(User $actor,string $companyId,string $obligationId,
        string $decisionId,string $decision,string $reason):string
    {
        $this->gate();$this->ids($companyId,$obligationId,$decisionId);
        $reason=trim($reason);
        if(!in_array($decision,['authorized','rejected','disputed'],true)||
            mb_strlen($reason)<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independent written obligation decision required.');
        $fingerprint=hash('sha256',json_encode([
            $companyId,$obligationId,$decision,$reason,(int)$actor->getKey()
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$companyId,$obligationId,
            $decisionId,$decision,$reason,$fingerprint):string {
            $obligation=DB::table('hall_inventory_financial_obligations')
                ->where('company_id',$companyId)->where('id',$obligationId)
                ->lockForUpdate()->first();
            if(!$obligation)throw new ConflictHttpException('Manual agreement proposal not found.');
            $claim=DB::table('hall_inventory_financial_claims')
                ->where('company_id',$companyId)->where('id',$obligation->claim_id)
                ->lockForUpdate()->first();
            if(!$claim)throw new ConflictHttpException('Agreement original incident claim missing.');
            $this->authorize($actor,$companyId,(string)$obligation->source_hall_id);
            $doc=DB::table('hall_inventory_financial_counterparty_evidence')
                ->where('company_id',$companyId)->where('id',$obligation->evidence_id)->first();
            if(!$doc||(int)$obligation->proposed_by===(int)$actor->getKey()||
                (int)$claim->proposed_by===(int)$actor->getKey()||
                (int)$doc->recorded_by===(int)$actor->getKey())
                throw new AuthorizationException('A different original hall owner must authorize liability.');
            $this->verifyDocument($doc);
            $old=DB::table('hall_inventory_financial_obligation_decisions')
                ->where('company_id',$companyId)->where('obligation_id',$obligationId)->first();
            if($old){
                if((string)$old->id!==$decisionId||
                    (int)$old->decided_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Obligation already independently decided.');
                return $decisionId;
            }
            if($decision==='authorized' &&
                DB::table('hall_inventory_financial_obligation_decisions')
                    ->where('company_id',$companyId)->where('claim_id',$claim->id)
                    ->where('decision','authorized')->exists())
                throw new ConflictHttpException('Another binding agreement already authorized for this claim.');
            DB::table('hall_inventory_financial_obligation_decisions')->insert([
                'id'=>$decisionId,'company_id'=>$companyId,'obligation_id'=>$obligationId,
                'claim_id'=>$obligation->claim_id,'decision'=>$decision,
                'reason'=>$reason,'fingerprint'=>$fingerprint,'decided_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'manual_agreement_decided',$decisionId,
                (string)$obligation->loan_id,['obligationId'=>$obligationId,
                    'claimId'=>$obligation->claim_id,'decision'=>$decision,
                    'manualPartnerVerification'=>true,'receivableJournalPosted'=>false,
                    'paidMinor'=>0]);
            return $decisionId;
        },3);
    }

    public function history(User $actor,string $companyId,string $hallId,string $loanId):array
    {
        $this->gate();$this->ids($companyId,$hallId,$loanId);
        $this->authorize($actor,$companyId,$hallId);
        if(!DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$companyId)->where('id',$loanId)
            ->where('lending_hall_id',$hallId)->where('direction','external_out')->exists())
            throw new AuthorizationException('Other hall obligation history forbidden.');
        return DB::table('hall_inventory_financial_obligations as p')
            ->leftJoin('hall_inventory_financial_obligation_decisions as d',function($j):void {
                $j->on('d.company_id','=','p.company_id')->on('d.obligation_id','=','p.id');
            })
            ->where('p.company_id',$companyId)->where('p.source_hall_id',$hallId)
            ->where('p.loan_id',$loanId)->orderByDesc('p.created_at')
            ->orderByDesc('p.id')->limit(100)
            ->get(['p.id','p.claim_id','p.evidence_id','p.amount_minor','p.currency',
                'p.proposed_by','p.identity_check_method','d.decision','d.decided_by'])
            ->map(static fn($row):array=>[
                'id'=>(string)$row->id,'claimId'=>(string)$row->claim_id,
                'evidenceId'=>(string)$row->evidence_id,
                'amountMinor'=>(int)$row->amount_minor,'currency'=>(string)$row->currency,
                'manualCheck'=>(string)$row->identity_check_method,
                'proposedBy'=>(int)$row->proposed_by,'decision'=>$row->decision,
                'decidedBy'=>$row->decided_by,'paidMinor'=>0,
                'receivableJournalPosted'=>false
            ])->all();
    }

    private function audit(User $actor,string $companyId,string $name,string $id,
        string $loanId,array $after):void
    {
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.'.$name,'entity_type'=>'hall_inventory_financial_obligation',
            'entity_id'=>$id,'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId,'collectionReceived'=>false,
                'financialPosting'=>false],JSON_THROW_ON_ERROR)
        ]);
    }
}
