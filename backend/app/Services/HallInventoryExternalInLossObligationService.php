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
 * Provisional EXTERNAL_IN partner loss obligation and independent decision.
 * Approved means only an internal commercial position. This NEVER creates an
 * invoice, recognized receivable/payable, journal, payment, partner admission,
 * stock movement, waiver or loan closure.
 */
final class HallInventoryExternalInLossObligationService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate():void {
        foreach(['loan_drafts_enabled','external_borrowed_intake_enabled',
            'external_borrowed_return_enabled','external_borrowed_discrepancy_enabled',
            'external_in_loss_obligation_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new ConflictHttpException('External-in partner loss obligations are not released.');
    }
    public function propose(User $actor,string $companyId,string $hallId,string $discrepancyId,
        string $obligationId,string $currency,int $amountMinor,string $reason):string {
        $this->gate();
        foreach([$companyId,$hallId,$discrepancyId,$obligationId] as $id)
            if(!Str::isUuid($id))
                throw new InvalidArgumentException('Exact external-in obligation UUIDs are required.');
        if(!preg_match('/\A[A-Z]{3}\z/D',$currency)||$amountMinor<1||$amountMinor>100000000000||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Invalid provisional partner loss obligation.');
        $reason=trim($reason);
        return DB::transaction(function()use($actor,$companyId,$hallId,$discrepancyId,
            $obligationId,$currency,$amountMinor,$reason):string {
            $ref=DB::table('hall_inventory_external_in_discrepancies')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$discrepancyId)->first(['loan_id','batch_id','reported_by']);
            if(!$ref)throw new AuthorizationException('Partner discrepancy is outside this hall.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$ref->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||
                (string)$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Obligation must belong to the original borrowing-hall loan.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current borrowing-hall owner/admin required.');
            $discrepancy=DB::table('hall_inventory_external_in_discrepancies')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$discrepancyId)->lockForUpdate()->first();
            if(!$discrepancy)throw new ConflictHttpException('Partner discrepancy was not found.');
            if((int)$discrepancy->reported_by===(int)$actor->getKey())
                throw new AuthorizationException('Partner-count recorder cannot propose the loss obligation.');
            $batch=DB::table('hall_inventory_external_in_return_batches')
                ->where('company_id',$companyId)->where('id',$discrepancy->batch_id)->first();
            if($batch && (int)$batch->dispatched_by===(int)$actor->getKey())
                throw new AuthorizationException('Return dispatcher cannot propose the partner loss obligation.');
            $decision=DB::table('hall_inventory_external_in_discrepancy_decisions')
                ->where('company_id',$companyId)->where('discrepancy_id',$discrepancyId)->first();
            if(!$decision||$decision->decision!=='substantiated')
                throw new ConflictHttpException('Only an independently substantiated partner discrepancy may ground a loss obligation.');
            $lines=DB::table('hall_inventory_external_in_discrepancy_lines')
                ->where('company_id',$companyId)->where('discrepancy_id',$discrepancyId)
                ->get(['shipped_quantity','received_quantity','damaged_quantity']);
            if($lines->isEmpty())
                throw new ConflictHttpException('Partner discrepancy has no immutable item evidence.');
            $basis=0;
            foreach($lines as $line)
                $basis+=max(0,(int)$line->shipped_quantity-(int)$line->received_quantity)+
                    (int)$line->damaged_quantity;
            if($basis<1)
                throw new ConflictHttpException('No partner-reported missing or damaged units can ground an obligation.');
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$discrepancyId,
                (string)$decision->id,$basis,$currency,$amountMinor,$reason,
                (int)$actor->getKey()],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_external_in_loss_obligations')
                ->where('company_id',$companyId)->where('discrepancy_id',$discrepancyId)->first();
            if($old) {
                if((string)$old->id!==$obligationId||
                    (int)$old->proposed_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('External-in loss obligation already exists with different immutable values.');
                return $obligationId;
            }
            DB::table('hall_inventory_external_in_loss_obligations')->insert([
                'id'=>$obligationId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'loan_id'=>$loan->id,'discrepancy_id'=>$discrepancyId,
                'decision_id'=>$decision->id,'quantity_basis'=>$basis,
                'currency'=>$currency,'amount_minor'=>$amountMinor,'reason'=>$reason,
                'fingerprint'=>$fingerprint,'proposed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'proposed',$obligationId,(string)$loan->id,
                ['discrepancyId'=>$discrepancyId,'quantityBasis'=>$basis,
                 'currency'=>$currency,'amountMinor'=>$amountMinor,
                 'journalPosted'=>false,'paymentRecorded'=>false,'loanClosed'=>false]);
            return $obligationId;
        },3);
    }
    public function decide(User $actor,string $companyId,string $hallId,string $obligationId,
        string $decisionId,string $decision,string $reason):string {
        $this->gate();
        foreach([$companyId,$hallId,$obligationId,$decisionId] as $id)
            if(!Str::isUuid($id))
                throw new InvalidArgumentException('Exact external-in obligation decision UUIDs are required.');
        if(!in_array($decision,['approved','rejected','disputed'],true)||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independent partner loss obligation decision and reason required.');
        $reason=trim($reason);
        return DB::transaction(function()use($actor,$companyId,$hallId,$obligationId,
            $decisionId,$decision,$reason):string {
            $ref=DB::table('hall_inventory_external_in_loss_obligations')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$obligationId)->first(['loan_id','discrepancy_id','proposed_by']);
            if(!$ref)throw new AuthorizationException('External-in loss obligation is outside this hall.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$ref->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||
                (string)$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Obligation no longer belongs to the original borrowing-hall loan.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current borrowing-hall owner/admin required.');
            $obligation=DB::table('hall_inventory_external_in_loss_obligations')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$obligationId)->lockForUpdate()->first();
            if(!$obligation)throw new ConflictHttpException('External-in loss obligation was not found.');
            if((int)$obligation->proposed_by===(int)$actor->getKey())
                throw new AuthorizationException('Obligation proposer cannot independently decide their own amount.');
            $discrepancy=DB::table('hall_inventory_external_in_discrepancies')
                ->where('company_id',$companyId)->where('id',$obligation->discrepancy_id)->first();
            if(!$discrepancy||(int)$discrepancy->reported_by===(int)$actor->getKey())
                throw new AuthorizationException('Partner-count recorder cannot decide the obligation.');
            $old=DB::table('hall_inventory_external_in_loss_obligation_decisions')
                ->where('company_id',$companyId)->where('obligation_id',$obligationId)->first();
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$obligationId,
                (int)$actor->getKey(),$decision,$reason],JSON_THROW_ON_ERROR));
            if($old) {
                if((string)$old->id!==$decisionId||
                    (int)$old->decided_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('External-in loss obligation already has an immutable decision.');
                return $decisionId;
            }
            DB::table('hall_inventory_external_in_loss_obligation_decisions')->insert([
                'id'=>$decisionId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'obligation_id'=>$obligationId,'decision'=>$decision,'reason'=>$reason,
                'fingerprint'=>$fingerprint,'decided_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'reviewed',$decisionId,(string)$loan->id,
                ['obligationId'=>$obligationId,'decision'=>$decision,
                 'journalPosted'=>false,'paymentRecorded'=>false,'loanClosed'=>false]);
            return $decisionId;
        },3);
    }
    /** Read-only current-hall obligation history for inventory staff. */
    public function recent(User $actor,string $companyId,string $hallId):array {
        $this->gate();
        foreach([$companyId,$hallId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Invalid external-in obligation scope.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('External-in obligation history is hall-restricted.');
        return DB::table('hall_inventory_external_in_loss_obligations as o')
            ->leftJoin('hall_inventory_external_in_loss_obligation_decisions as d',function($j):void {
                $j->on('d.company_id','=','o.company_id')->on('d.obligation_id','=','o.id');
            })->where('o.company_id',$companyId)->where('o.hall_id',$hallId)
            ->orderByDesc('o.created_at')->orderByDesc('o.id')->limit(100)
            ->get(['o.id','o.discrepancy_id','o.quantity_basis','o.currency',
                'o.amount_minor','o.proposed_by','d.decision','d.decided_by'])
            ->map(static fn($x):array=>[
                'id'=>(string)$x->id,'discrepancyId'=>(string)$x->discrepancy_id,
                'quantityBasis'=>(int)$x->quantity_basis,'currency'=>(string)$x->currency,
                'amountMinor'=>(int)$x->amount_minor,'proposedBy'=>(int)$x->proposed_by,
                'decision'=>$x->decision,'decidedBy'=>$x->decided_by,
                'journalPosted'=>false,'paymentRecorded'=>false,'loanClosed'=>false,
            ])->all();
    }
    private function audit(User $actor,string $companyId,string $action,string $id,
        string $loanId,array $data):void {
        DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.external_in_loss_obligation_'.$action,
            'entity_type'=>'hall_inventory_external_in_loss_obligation','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId],JSON_THROW_ON_ERROR)]);
    }
}
