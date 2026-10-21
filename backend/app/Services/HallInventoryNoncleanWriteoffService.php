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
 * Independent physical loss authorization. A stock write-off is NOT a waiver,
 * payment, settlement, journal, partner admission or final loan closure.
 */
final class HallInventoryNoncleanWriteoffService {
    public function __construct(private readonly TenantAccess $access) {}
    private function gate():void {
        foreach(['loan_nonclean_reconciliation_enabled','loan_nonclean_writeoffs_enabled',
            'loan_incidents_enabled','loan_incident_reviews_enabled',
            'external_out_dispatch_enabled','external_out_return_enabled'] as $flag)
            if(!config('inventory_controls.'.$flag))
                throw new ConflictHttpException('Independent physical write-off is not released.');
    }
    private function scope(User $actor,string $companyId,string $hallId):void {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Current original lending-hall owner/admin required.');
    }
    private function ids(string ...$ids):void {
        foreach($ids as $id)if(!Str::isUuid($id))
            throw new InvalidArgumentException('Exact original inventory scope UUID required.');
    }
    private function incident(string $companyId,string $incidentId):object {
        $r=DB::table('hall_inventory_loan_incident_reports as i')
            ->join('hall_inventory_loan_incident_decisions as d',function($j):void {
                $j->on('d.company_id','=','i.company_id')->on('d.report_id','=','i.id');
            })->join('hall_inventory_loan_drafts as l',function($j):void {
                $j->on('l.company_id','=','i.company_id')->on('l.id','=','i.loan_id');
            })->where('i.company_id',$companyId)->where('i.id',$incidentId)
            ->where('d.decision','substantiated')->where('l.direction','external_out')
            ->first(['i.id','i.company_id','i.loan_id','i.source_hall_id',
                'i.source_item_id','i.kind','i.quantity','i.reported_by',
                'd.reviewed_by','l.created_by']);
        if(!$r||!in_array($r->kind,['damage','missing'],true))
            throw new ConflictHttpException('Only independently substantiated external-owned physical losses qualify.');
        return $r;
    }
    public function propose(User $actor,string $companyId,string $hallId,string $incidentId,
        string $proposalId,int $quantity,string $reason):string {
        $this->gate();$this->ids($companyId,$hallId,$incidentId,$proposalId);
        $reason=trim($reason);
        if($quantity<1||$quantity>100000000||mb_strlen($reason)<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Document exact loss quantity and physical disposition reason.');
        $this->scope($actor,$companyId,$hallId);
        return DB::transaction(function()use($actor,$companyId,$hallId,$incidentId,$proposalId,$quantity,$reason):string {
            $company=DB::table('companies')->where('id',$companyId)->where('is_active',true)->lockForUpdate()->first();
            if(!$company)throw new AuthorizationException('Inactive inventory company.');
            $incident=$this->incident($companyId,$incidentId);
            if(DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$companyId)
                ->where('loan_id',$incident->loan_id)->exists())
                throw new ConflictHttpException('Finalized non-clean loan inventory cannot be changed.');

            if((string)$incident->source_hall_id!==$hallId||$quantity>(int)$incident->quantity)
                throw new ConflictHttpException('Requested write-off exceeds original substantiated hall evidence.');
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$incidentId,
                (int)$actor->getKey(),$quantity,$reason],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_nonclean_writeoff_proposals')
                ->where('company_id',$companyId)->where('incident_id',$incidentId)->first();
            if($old) {
                if($old->id!==$proposalId||!hash_equals($old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('An immutable physical disposition proposal already exists.');
                return $proposalId;
            }
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$incident->loan_id)->lockForUpdate()->first();
            if(!$loan||DB::table('hall_inventory_external_out_clean_closures')
                ->where('company_id',$companyId)->where('loan_id',$incident->loan_id)->exists())
                throw new ConflictHttpException('Loan unavailable for abnormal physical disposition.');
            DB::table('hall_inventory_nonclean_writeoff_proposals')->insert([
                'id'=>$proposalId,'company_id'=>$companyId,'loan_id'=>$incident->loan_id,
                'hall_id'=>$hallId,'item_id'=>$incident->source_item_id,'incident_id'=>$incidentId,
                'kind'=>$incident->kind,'quantity'=>$quantity,'reason'=>$reason,
                'fingerprint'=>$fingerprint,'proposed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'nonclean_writeoff_proposed',$proposalId,$incident->loan_id,
                ['physicalQuantity'=>$quantity,'incidentId'=>$incidentId,'financialSettlement'=>false]);
            return $proposalId;
        },3);
    }
    public function decide(User $actor,string $companyId,string $hallId,string $proposalId,
        string $decisionId,string $decision,string $reason):string {
        $this->gate();$this->ids($companyId,$hallId,$proposalId,$decisionId);
        $reason=trim($reason);
        if(!in_array($decision,['approved','rejected'],true)||mb_strlen($reason)<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independent disposition decision and reason required.');
        $this->scope($actor,$companyId,$hallId);
        return DB::transaction(function()use($actor,$companyId,$hallId,$proposalId,$decisionId,$decision,$reason):string {
            $company=DB::table('companies')->where('id',$companyId)->where('is_active',true)
                ->lockForUpdate()->first();
            if(!$company)throw new AuthorizationException('Inactive company.');
            $proposal=DB::table('hall_inventory_nonclean_writeoff_proposals')
                ->where('company_id',$companyId)->where('hall_id',$hallId)->where('id',$proposalId)
                ->lockForUpdate()->first();
            if(!$proposal)throw new AuthorizationException('Other hall loss proposal is private.');
            $incident=$this->incident($companyId,$proposal->incident_id);
            if(DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$companyId)
                ->where('loan_id',$proposal->loan_id)->exists())
                throw new ConflictHttpException('Finalized non-clean loan inventory cannot be changed.');

            if((string)$incident->loan_id!==(string)$proposal->loan_id||
                (string)$incident->source_item_id!==(string)$proposal->item_id||
                (string)$incident->source_hall_id!==$hallId||
                $incident->kind!==$proposal->kind||
                (int)$proposal->quantity>(int)$incident->quantity)
                throw new ConflictHttpException('Original incident and physical proposal no longer reconcile.');
            if(in_array((int)$actor->getKey(),[(int)$proposal->proposed_by,
                (int)$incident->reported_by,(int)$incident->reviewed_by],true))
                throw new AuthorizationException('Independent physical write-off reviewer cannot approve their own custody evidence.');
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$proposalId,
                (int)$actor->getKey(),$decision,$reason],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_nonclean_writeoff_decisions')
                ->where('company_id',$companyId)->where('proposal_id',$proposalId)->first();
            if($old){
                if($old->id!==$decisionId||!hash_equals($old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Another final physical disposition decision already exists.');
                return $decisionId;
            }
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$proposal->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_out'||(string)$loan->lending_hall_id!==$hallId||
                DB::table('hall_inventory_external_out_clean_closures')
                    ->where('company_id',$companyId)->where('loan_id',$proposal->loan_id)->exists())
                throw new ConflictHttpException('Outside owned source loan is not open for abnormal resolution.');
            $movementId=null;
            if($decision==='approved') {
                $snapshot=app(HallInventoryNoncleanReconciliationService::class)
                    ->report($actor,$companyId,$hallId,$proposal->loan_id);
                $line=collect($snapshot['items'])->firstWhere('itemId',(string)$proposal->item_id);
                if(!$line)throw new ConflictHttpException('Original physical loan item missing.');
                $previous=(int)DB::table('hall_inventory_nonclean_writeoff_proposals as p')
                    ->join('hall_inventory_nonclean_writeoff_decisions as d',function($j):void{
                        $j->on('d.company_id','=','p.company_id')->on('d.proposal_id','=','p.id');
                    })->where('p.company_id',$companyId)->where('p.loan_id',$proposal->loan_id)
                    ->where('p.item_id',$proposal->item_id)->where('p.kind',$proposal->kind)
                    ->where('d.decision','approved')->sum('p.quantity');
                $limit=$proposal->kind==='damage'?(int)$line['damaged']:(int)$line['unreturned'];
                if($previous+(int)$proposal->quantity>$limit)
                    throw new ConflictHttpException('Another approved loss already consumed physically evidenced units.');
                $position=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                    ->where('hall_id',$hallId)->where('item_id',$proposal->item_id)
                    ->lockForUpdate()->first();
                if(!$position)throw new ConflictHttpException('Original company hall stock book missing.');
                $before=[
                    'ownedUsable'=>(int)$position->owned_usable,
                    'ownedOnLoan'=>(int)$position->owned_on_loan,
                    'borrowedUsable'=>(int)$position->borrowed_usable,
                    'borrowedTransit'=>(int)$position->borrowed_transit,
                    'ownedDamaged'=>(int)$position->owned_damaged,
                    'borrowedDamaged'=>(int)$position->borrowed_damaged
                ];
                $kind=$proposal->kind==='damage'?'writeoff_owned_damaged':'writeoff_owned_on_loan';
                $after=app(HallInventoryPositionTransition::class)
                    ->apply($before,$kind,(int)$proposal->quantity);
                $rev=(int)$position->revision;
                $changed=DB::table('hall_inventory_positions')->where('id',$position->id)
                    ->where('company_id',$companyId)->where('revision',$rev)
                    ->update(['owned_usable'=>$after['ownedUsable'],
                        'owned_on_loan'=>$after['ownedOnLoan'],'owned_damaged'=>$after['ownedDamaged'],
                        'borrowed_usable'=>$after['borrowedUsable'],
                        'borrowed_transit'=>$after['borrowedTransit'],
                        'borrowed_damaged'=>$after['borrowedDamaged'],
                        'revision'=>$rev+1,'updated_at'=>now()]);
                if($changed!==1)throw new ConflictHttpException('Concurrent inventory position change; retry reconciliation.');
                $movementId=(string)Str::uuid();
                DB::table('hall_inventory_movement_events')->insert([
                    'id'=>$movementId,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'item_id'=>$proposal->item_id,'position_id'=>$position->id,'event_kind'=>$kind,
                    'quantity'=>$proposal->quantity,'revision_before'=>$rev,'revision_after'=>$rev+1,
                    'request_fingerprint'=>$fingerprint,
                    'reason'=>'Independently authorized non-clean loss disposition '.$proposalId,
                    'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                    'actor_id'=>$actor->getKey()
                ]);
            }
            DB::table('hall_inventory_nonclean_writeoff_decisions')->insert([
                'id'=>$decisionId,'company_id'=>$companyId,'proposal_id'=>$proposalId,
                'loan_id'=>$proposal->loan_id,'hall_id'=>$hallId,'item_id'=>$proposal->item_id,
                'decision'=>$decision,'reason'=>$reason,'fingerprint'=>$fingerprint,
                'movement_id'=>$movementId,'decided_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'nonclean_writeoff_decided',$decisionId,$proposal->loan_id,
                ['proposalId'=>$proposalId,'decision'=>$decision,'movementId'=>$movementId,
                 'financialWaiver'=>false,'cashPayment'=>false,'loanClosure'=>false]);
            return $decisionId;
        },3);
    }
    public function history(User $actor,string $companyId,string $hallId,string $loanId):array {
        $this->gate();$this->ids($companyId,$hallId,$loanId);
        $this->scope($actor,$companyId,$hallId);
        if(!DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
            ->where('id',$loanId)->where('lending_hall_id',$hallId)
            ->where('direction','external_out')->exists())
            throw new AuthorizationException('Outside owned physical write-off history is hall private.');
        return DB::table('hall_inventory_nonclean_writeoff_proposals as p')
            ->leftJoin('hall_inventory_nonclean_writeoff_decisions as d',function($j):void {
                $j->on('d.company_id','=','p.company_id')->on('d.proposal_id','=','p.id');
            })->where('p.company_id',$companyId)->where('p.hall_id',$hallId)
            ->where('p.loan_id',$loanId)->orderBy('p.created_at')->orderBy('p.id')
            ->get(['p.id','p.incident_id','p.item_id','p.kind','p.quantity','p.reason',
                'p.proposed_by','d.decision','d.decided_by','d.movement_id'])
            ->map(static fn($r):array=>[
                'id'=>(string)$r->id,'incidentId'=>(string)$r->incident_id,
                'itemId'=>(string)$r->item_id,'kind'=>(string)$r->kind,
                'quantity'=>(int)$r->quantity,'reason'=>(string)$r->reason,
                'proposedBy'=>(int)$r->proposed_by,'decision'=>$r->decision,
                'decidedBy'=>$r->decided_by,'movementId'=>$r->movement_id
            ])->all();
    }
    public function eligible(User $actor,string $companyId,string $hallId,string $loanId):array {
        $this->history($actor,$companyId,$hallId,$loanId);
        return DB::table('hall_inventory_loan_incident_reports as i')
            ->join('hall_inventory_loan_incident_decisions as d',function($j):void {
                $j->on('d.company_id','=','i.company_id')->on('d.report_id','=','i.id');
            })->leftJoin('hall_inventory_nonclean_writeoff_proposals as p',function($j):void {
                $j->on('p.company_id','=','i.company_id')->on('p.incident_id','=','i.id');
            })->where('i.company_id',$companyId)->where('i.source_hall_id',$hallId)
            ->where('i.loan_id',$loanId)->where('d.decision','substantiated')
            ->whereIn('i.kind',['damage','missing'])->whereNull('p.id')
            ->get(['i.id','i.source_item_id','i.kind','i.quantity'])
            ->map(static fn($r):array=>[
                'id'=>(string)$r->id,'itemId'=>(string)$r->source_item_id,
                'kind'=>(string)$r->kind,'quantity'=>(int)$r->quantity
            ])->all();
    }
    private function audit(User $actor,string $companyId,string $action,string $id,string $loanId,array $data):void {
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.'.$action,
            'entity_type'=>'hall_inventory_nonclean_writeoff','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId,'onlyPhysicalBook'=>true],JSON_THROW_ON_ERROR)
        ]);
    }
}