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
 * Read-only inventory loan DRAFT review, scoped to authorized sending or receiving hall.
 *
 * This never dispatches, receives, transfers, reconciles or closes stock.
 * Vendor partner private contact and ciphertext are intentionally excluded.
 */
final class HallInventoryLoanDraftReview
{
    public function __construct(private readonly TenantAccess $access) {}

    /** @return array<int,array<string,mixed>> */
    public function recent(User $actor, string $companyId, string $hallId, int $limit=20): array
    {
        if (! config('inventory_controls.loan_drafts_enabled')) {
            throw new ConflictHttpException('Inventory loan drafts are not released.');
        }
        if (! Str::isUuid($companyId) || ! Str::isUuid($hallId)
            || $limit<1 || $limit>20) {
            throw new InvalidArgumentException('Invalid inventory loan review request.');
        }

        // Do not authorize based on a Livewire-rendered hall list or cached
        // user roles. TenantAccess rechecks persisted membership and license.
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$hallId)->where('is_active',true)->first();
        if (! $hall || ! Auth::check()
            || (int) Auth::id() !== (int) $actor->getKey()
            || ! $this->access->canManageHallInventory($actor,$hall)) {
            throw new AuthorizationException('Inventory loan drafts are not accessible for this hall.');
        }

        $drafts=DB::table('hall_inventory_loan_drafts as d')
            ->leftJoin('hall_inventory_loan_dispatches as ld', function ($join):void {
                $join->on('ld.company_id','=','d.company_id')
                    ->on('ld.loan_id','=','d.id');
            })
            ->leftJoinSub(
                DB::table('hall_inventory_internal_loan_return_batches')
                    ->select(['hall_inventory_internal_loan_return_batches.company_id',
                        'hall_inventory_internal_loan_return_batches.loan_id'])
                    ->leftJoin('hall_inventory_internal_loan_return_acceptances as ra',function($join):void {
                        $join->on('ra.company_id','=','hall_inventory_internal_loan_return_batches.company_id')
                            ->on('ra.return_batch_id','=','hall_inventory_internal_loan_return_batches.id');
                    })
                    ->selectRaw('COUNT(*) AS return_batch_count, COUNT(ra.id) AS acknowledged_batch_count')
                    ->groupBy('hall_inventory_internal_loan_return_batches.company_id',
                        'hall_inventory_internal_loan_return_batches.loan_id'),'rb',function($join):void {
                    $join->on('rb.company_id','=','d.company_id')
                        ->on('rb.loan_id','=','d.id');
                })
            ->leftJoin('hall_inventory_internal_loan_closures as lc',function($join):void {
                $join->on('lc.company_id','=','d.company_id')->on('lc.loan_id','=','d.id');
            })
            ->leftJoin('hall_inventory_internal_loan_receipts as lr', function ($join):void {
                $join->on('lr.company_id','=','d.company_id')
                    ->on('lr.loan_id','=','d.id');
            })
            ->leftJoin('hall_inventory_external_partners as p', function ($join):void {
                $join->on('p.id','=','d.external_partner_id')
                    ->on('p.company_id','=','d.company_id');
            })
            ->leftJoin('halls as lh', function ($join):void {
                $join->on('lh.id','=','d.lending_hall_id')
                    ->on('lh.company_id','=','d.company_id');
            })
            ->leftJoin('halls as bh', function ($join):void {
                $join->on('bh.id','=','d.borrowing_hall_id')
                    ->on('bh.company_id','=','d.company_id');
            })
            ->where('d.company_id',$companyId)
            ->where(function ($query) use ($hallId):void {
                $query->where('d.lending_hall_id',$hallId)
                    ->orWhere(function ($q) use ($hallId):void {
                        $q->where('d.direction','internal')
                            ->where('d.borrowing_hall_id',$hallId);
                    })
                    ->orWhere(function ($q) use ($hallId):void {
                        $q->where('d.direction','external_in')
                            ->where('d.borrowing_hall_id',$hallId);
                    });
            })
            ->orderByDesc('d.created_at')->orderByDesc('d.id')
            ->limit($limit)
            ->get([
                'd.id','d.direction','d.lending_hall_id','d.borrowing_hall_id',
                'd.expected_return_date','d.purpose','d.created_at',
                'ld.id as dispatch_id','ld.created_at as dispatched_at',
                'lc.id as closure_id',
                'lr.id as receipt_id','lr.created_at as received_at',
                'rb.return_batch_count','rb.acknowledged_batch_count',
                'p.name as external_partner_name',
                'lh.name as lending_hall_name','bh.name as borrowing_hall_name',
            ]);
        if($drafts->isEmpty())return [];

        $lines=DB::table('hall_inventory_loan_draft_lines')
            ->where('company_id',$companyId)
            ->whereIn('loan_id',$drafts->pluck('id')->all())
            ->orderBy('loan_id')->orderBy('item_name_snapshot')->orderBy('item_id')
            ->get(['loan_id','item_id','item_name_snapshot','unit_snapshot','requested_quantity'])
            ->groupBy('loan_id');

        // Private read-only per-loan physical custody reconciliation. Use
        // actual independently acknowledged receipt lines, never catalog totals.
        $loanIds=$drafts->pluck('id')->all();
        $received=DB::table('hall_inventory_internal_loan_receipt_lines as rl')
            ->join('hall_inventory_internal_loan_receipts as rec',function($j):void {
                $j->on('rec.company_id','=','rl.company_id')
                    ->on('rec.id','=','rl.receipt_id');
            })
            ->where('rec.company_id',$companyId)
            ->whereIn('rec.loan_id',$loanIds)
            ->select('rec.loan_id')
            ->selectRaw('SUM(rl.quantity) AS received_quantity')
            ->groupBy('rec.loan_id')->pluck('received_quantity','rec.loan_id');

        $returns=DB::table('hall_inventory_internal_loan_return_batches as b')
            ->join('hall_inventory_internal_loan_return_lines as l',function($j):void {
                $j->on('l.company_id','=','b.company_id')
                    ->on('l.return_batch_id','=','b.id');
            })
            ->leftJoin('hall_inventory_internal_loan_return_acceptances as a',function($j):void {
                $j->on('a.company_id','=','b.company_id')
                    ->on('a.return_batch_id','=','b.id');
            })
            ->leftJoin('hall_inventory_internal_loan_return_acceptance_lines as al',function($j):void {
                $j->on('al.company_id','=','l.company_id')
                    ->on('al.acceptance_id','=','a.id')
                    ->on('al.source_item_id','=','l.source_item_id');
            })
            ->where('b.company_id',$companyId)->whereIn('b.loan_id',$loanIds)
            ->select('b.loan_id')
            ->selectRaw('SUM(l.quantity) AS shipped_quantity')
            ->selectRaw('SUM(CASE WHEN a.id IS NULL THEN l.quantity ELSE 0 END) AS source_ack_pending')
            ->selectRaw('SUM(COALESCE(al.good_quantity,0)) AS returned_good')
            ->selectRaw('SUM(COALESCE(al.damaged_quantity,0)) AS returned_damaged')
            ->selectRaw('SUM(COALESCE(al.unresolved_quantity,0)) AS unresolved_source')
            ->selectRaw('SUM(CASE WHEN a.id IS NOT NULL AND al.id IS NULL THEN l.quantity ELSE 0 END) AS missing_review_lines')
            ->groupBy('b.loan_id')->get()->keyBy('loan_id');

        return $drafts->map(static function ($draft) use ($lines,$hallId,$received,$returns):array {
            $receiptCount=(int)($received->get($draft->id)??0);
            $stats=$returns->get($draft->id);
            $shipped=$stats?(int)$stats->shipped_quantity:0;
            $good=$stats?(int)$stats->returned_good:0;
            $damaged=$stats?(int)$stats->returned_damaged:0;
            $unresolved=$stats?(int)$stats->unresolved_source:0;
            $pending=$stats?(int)$stats->source_ack_pending:0;
            $missing=$stats?(int)$stats->missing_review_lines:0;
            $reconciliation=($draft->direction==='internal' && $draft->receipt_id!==null)
                ? [
                    'receivedQuantity'=>$receiptCount,
                    'shippedQuantity'=>$shipped,
                    'notYetShipped'=>max(0,$receiptCount-$shipped),
                    'sourceAcknowledgedGood'=>$good,
                    'sourceAcknowledgedDamaged'=>$damaged,
                    'sourceAcknowledgmentPending'=>$pending,
                    'sourceUnresolved'=>$unresolved,
                    'stillUnsettled'=>max(0,$receiptCount-$good-$damaged),
                    'integrityWarning'=>$shipped>$receiptCount ||
                        $good+$damaged+$unresolved+$pending+$missing!==$shipped ||
                        $missing>0,
                    'loanCompleted'=>$draft->closure_id!==null,
                    'financiallySettled'=>false,
                ] : null;

            return [
                'id'=>(string)$draft->id,
                'direction'=>(string)$draft->direction,
                'lendingHallId'=>$draft->lending_hall_id,
                'borrowingHallId'=>$draft->borrowing_hall_id,
                'externalPartnerName'=>$draft->external_partner_name,
                'lendingHallName'=>$draft->lending_hall_name,
                'borrowingHallName'=>$draft->borrowing_hall_name,
                'expectedReturnDate'=>(string)$draft->expected_return_date,
                // Informational only: a past due date has no charging or closure effect.
                // Unshipped drafts are never labeled overdue physical custody.
                'overdueWithOutstandingCustody'=>$draft->dispatch_id !== null
                    && (string)$draft->expected_return_date < now()->toDateString()
                    && ($draft->receipt_id === null || $reconciliation === null
                        || $reconciliation['stillUnsettled'] > 0
                        || $reconciliation['integrityWarning']),
                'purpose'=>(string)$draft->purpose,
                'createdAt'=>(string)$draft->created_at,
                'status'=>$draft->closure_id !== null ? 'clean_loan_closed' : ($draft->receipt_id !== null
                    ? ((int)($draft->return_batch_count??0)>0
                        ? ((int)($draft->acknowledged_batch_count??0) < (int)$draft->return_batch_count
                            ? 'partial_return_in_transit_source_ack_pending'
                            : 'return_acknowledged_outstanding_reconciliation_required')
                        : 'destination_received_return_pending')
                    : ($draft->dispatch_id !== null
                        ? 'source_dispatched_receipt_pending' : 'draft_only')),
                'viewRole'=>($draft->direction==='internal' &&
                    $draft->borrowing_hall_id=== $hallId) ? 'receiving' : 'originating',
                'stockMoved'=>$draft->dispatch_id !== null,
                'sourceDispatchRecorded'=>$draft->dispatch_id !== null,
                'destinationReceiptConfirmed'=>$draft->receipt_id !== null,
                'receivedAt'=>$draft->received_at !== null
                    ? (string)$draft->received_at : null,
                'dispatchedAt'=>$draft->dispatched_at !== null
                    ? (string)$draft->dispatched_at : null,
                'returnReconciliation'=>$reconciliation,
                'lines'=>($lines->get($draft->id)??collect())->map(
                    static fn($line):array=>[
                        'itemId'=>(string)$line->item_id,
                        'name'=>(string)$line->item_name_snapshot,
                        'unit'=>(string)$line->unit_snapshot,
                        'quantity'=>(int)$line->requested_quantity,
                    ]
                )->all(),
            ];
        })->all();
    }
}
