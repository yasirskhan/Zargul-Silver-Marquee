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
 * Staff incident evidence only. Never posts a loss, assigns liability,
 * changes stock custody, settles a deposit, or completes a loan.
 */
final class HallInventoryLoanIncidentService
{
    public function __construct(private readonly TenantAccess $access) {}

    public function report(User $actor, string $companyId, string $loanId,
        string $requestId, string $sourceItemId, string $kind,
        int $quantity, string $reason): string
    {
        $this->gated();
        if (!Str::isUuid($companyId) || !Str::isUuid($loanId) ||
            !Str::isUuid($requestId) || !Str::isUuid($sourceItemId) ||
            !in_array($kind,['damage','missing','dispute'],true) ||
            $quantity<1 || $quantity>100000000 ||
            mb_strlen(trim($reason))<10 || mb_strlen($reason)>500)
            throw new InvalidArgumentException('Invalid inventory incident details.');

        $reason=trim($reason);
        $fingerprint=hash('sha256',json_encode([
            $companyId,$loanId,$sourceItemId,$kind,$quantity,$reason,
            (int)$actor->getKey()
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$companyId,$loanId,
            $requestId,$sourceItemId,$kind,$quantity,$reason,$fingerprint):string {
            $loan=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$loanId)
                ->lockForUpdate()->first();
            if(!$loan || !in_array($loan->direction,['internal','external_out'],true) ||
                !$loan->lending_hall_id)
                throw new ConflictHttpException('An originating physically approved loan is required.');
            $external=$loan->direction==='external_out';
            if($external && (!config('inventory_controls.external_out_dispatch_enabled') ||
                !config('inventory_controls.external_out_return_enabled')))
                throw new ConflictHttpException('External owned loan incidents are not released.');
            if(!$external && !config('inventory_controls.loan_return_acceptance_enabled'))
                throw new ConflictHttpException('Internal incident reporting requires released return acceptance.');
            if(!$external && DB::table('hall_inventory_internal_loan_closures')
                ->where('company_id',$companyId)->where('loan_id',$loanId)->exists())
                throw new ConflictHttpException('Immutable clean loan closure prevents new incident reports.');
            $hall=Hall::query()->where('company_id',$companyId)
                ->where('id',$loan->lending_hall_id)->where('is_active',true)->first();
            if(!$hall || !Auth::check() ||
                (int)Auth::id()!==(int)$actor->getKey() ||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Only a current lending-hall owner or admin may report an incident.');

            $previous=DB::table('hall_inventory_loan_incident_reports')
                ->where('company_id',$companyId)->where('id',$requestId)->first();
            if($previous) {
                if(!hash_equals((string)$previous->request_fingerprint,$fingerprint))
                    throw new ConflictHttpException('Conflicting incident request replay.');
                return $requestId;
            }
            $line=DB::table('hall_inventory_loan_draft_lines')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('item_id',$sourceItemId)->first();
            if(!$line)throw new ConflictHttpException('The claimed item is not on the original loan.');
            if($external){
                $original=DB::table('hall_inventory_external_out_dispatch_lines as l')
                    ->join('hall_inventory_external_out_dispatches as d',function($j):void{
                        $j->on('d.company_id','=','l.company_id')->on('d.id','=','l.dispatch_id');
                    })
                    ->where('d.company_id',$companyId)->where('d.loan_id',$loanId)
                    ->where('d.hall_id',$hall->id)->where('l.item_id',$sourceItemId)
                    ->first(['l.quantity']);
                if(!$original)throw new ConflictHttpException('Outside-owned loan was not independently dispatched.');
                $physical=DB::table('hall_inventory_external_out_return_review_lines as l')
                    ->join('hall_inventory_external_out_return_reviews as a',function($j):void {
                        $j->on('a.company_id','=','l.company_id')->on('a.id','=','l.review_id');
                    })
                    ->where('a.company_id',$companyId)->where('a.loan_id',$loanId)
                    ->where('a.hall_id',$hall->id)->where('l.item_id',$sourceItemId)
                    ->selectRaw('COALESCE(SUM(l.good_quantity),0) as good')
                    ->selectRaw('COALESCE(SUM(l.damaged_quantity),0) as damaged')
                    ->first();
                $good=(int)($physical->good??0);
                $damaged=(int)($physical->damaged??0);
                $qty=(int)$original->quantity;
                $maximum=$kind==='damage'?$damaged:$qty-$good-$damaged;
                if($maximum<1||$quantity>$maximum)
                    throw new ConflictHttpException('External incident exceeds evidenced damaged or unresolved on-loan quantity.');
            }else{
                $receipt=DB::table('hall_inventory_internal_loan_receipts')
                    ->where('company_id',$companyId)->where('loan_id',$loanId)->first();
                if(!$receipt)throw new ConflictHttpException('Only independently received loan lines may be investigated.');

            $physical=DB::table('hall_inventory_internal_loan_return_acceptance_lines as al')
                ->join('hall_inventory_internal_loan_return_acceptances as a',
                    function($j):void {$j->on('a.company_id','=','al.company_id')
                        ->on('a.id','=','al.acceptance_id');})
                ->join('hall_inventory_internal_loan_return_batches as b',
                    function($j):void {$j->on('b.company_id','=','a.company_id')
                        ->on('b.id','=','a.return_batch_id');})
                ->where('al.company_id',$companyId)->where('b.loan_id',$loanId)
                ->where('al.source_item_id',$sourceItemId)
                ->selectRaw('COALESCE(SUM(al.good_quantity),0) as good')
                ->selectRaw('COALESCE(SUM(al.damaged_quantity),0) as damaged')
                ->first();
            $receiptLine=DB::table('hall_inventory_internal_loan_receipt_lines')
                ->where('company_id',$companyId)->where('receipt_id',$receipt->id)
                ->where('source_item_id',$sourceItemId)->first(['quantity']);
            if(!$receiptLine)
                throw new ConflictHttpException('No independently witnessed quantity for the affected item.');
            $received=(int)$receiptLine->quantity;
            $good=(int)($physical->good??0);
            $damaged=(int)($physical->damaged??0);
            $maximum=$kind==='damage' ? $damaged : $received-$good-$damaged;
            if($maximum<1 || $quantity>$maximum)
                throw new ConflictHttpException('Incident exceeds physically evidenced affected quantity.');
            }
            // Separate reports may describe the same event; they are not
            // additive financial claims, stock write-offs, or settlements.
            DB::table('hall_inventory_loan_incident_reports')->insert([
                'id'=>$requestId,'company_id'=>$companyId,'loan_id'=>$loanId,
                'source_hall_id'=>$hall->id,'source_item_id'=>$sourceItemId,
                'kind'=>$kind,'quantity'=>$quantity,'reason'=>$reason,
                'request_fingerprint'=>$fingerprint,'reported_by'=>$actor->getKey()
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$actor->getKey(),
                'action'=>$external?'hall_inventory.external_owned_loan_incident_reported':
                    'hall_inventory.internal_loan_incident_reported',
                'entity_type'=>'hall_inventory_loan_incident_report',
                'entity_id'=>$requestId,
                'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['loanId'=>$loanId,'itemId'=>$sourceItemId,
                    'kind'=>$kind,'quantity'=>$quantity],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['hallId'=>$hall->id],JSON_THROW_ON_ERROR)
            ]);
            return $requestId;
        },3);
    }

    public function history(User $actor,string $companyId,string $hallId,string $loanId):array
    {
        $this->gated();
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||!Str::isUuid($loanId))
            throw new InvalidArgumentException('Invalid loan incident history scope.');
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$hallId)->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Incident history is restricted to the originating owner/admin.');
        $loan=DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$companyId)->where('id',$loanId)->first();
        if(!$loan||$loan->lending_hall_id!==$hallId)
            throw new AuthorizationException('Incident loan scope mismatch.');
        if($loan->direction==='internal' && !config('inventory_controls.loan_return_acceptance_enabled'))
            throw new ConflictHttpException('Internal loan incident history is not released.');
        if($loan->direction==='external_out' &&
            (!config('inventory_controls.external_out_dispatch_enabled') ||
             !config('inventory_controls.external_out_return_enabled')))
            throw new ConflictHttpException('Outside owned incidents are not released.');
        return DB::table('hall_inventory_loan_incident_reports')
            ->where('company_id',$companyId)->where('source_hall_id',$hallId)
            ->where('loan_id',$loanId)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(100)->get(['id','source_item_id','kind','quantity',
                'reason','reported_by','created_at'])->map(static fn($row):array=>[
                'id'=>(string)$row->id,'itemId'=>(string)$row->source_item_id,
                'kind'=>(string)$row->kind,'quantity'=>(int)$row->quantity,
                'reason'=>(string)$row->reason,'reportedBy'=>(int)$row->reported_by,
                'createdAt'=>(string)$row->created_at,
                'financiallyResolved'=>false,'loanCompleted'=>false,
            ])->all();
    }

    private function gated():void
    {
        if(!config('inventory_controls.loan_incidents_enabled'))
            throw new ConflictHttpException('Private loan incident evidence is disabled.');
    }
}
