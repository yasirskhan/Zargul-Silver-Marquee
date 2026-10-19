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

        return $drafts->map(static function ($draft) use ($lines,$hallId):array {
            return [
                'id'=>(string)$draft->id,
                'direction'=>(string)$draft->direction,
                'lendingHallId'=>$draft->lending_hall_id,
                'borrowingHallId'=>$draft->borrowing_hall_id,
                'externalPartnerName'=>$draft->external_partner_name,
                'lendingHallName'=>$draft->lending_hall_name,
                'borrowingHallName'=>$draft->borrowing_hall_name,
                'expectedReturnDate'=>(string)$draft->expected_return_date,
                'purpose'=>(string)$draft->purpose,
                'createdAt'=>(string)$draft->created_at,
                'status'=>$draft->receipt_id !== null
                    ? ((int)($draft->return_batch_count??0)>0
                        ? ((int)($draft->acknowledged_batch_count??0) < (int)$draft->return_batch_count
                            ? 'partial_return_in_transit_source_ack_pending'
                            : 'return_acknowledged_outstanding_reconciliation_required')
                        : 'destination_received_return_pending')
                    : ($draft->dispatch_id !== null
                        ? 'source_dispatched_receipt_pending' : 'draft_only'),
                'viewRole'=>($draft->direction==='internal' &&
                    $draft->borrowing_hall_id=== $hallId) ? 'receiving' : 'originating',
                'stockMoved'=>$draft->dispatch_id !== null,
                'sourceDispatchRecorded'=>$draft->dispatch_id !== null,
                'destinationReceiptConfirmed'=>$draft->receipt_id !== null,
                'receivedAt'=>$draft->received_at !== null
                    ? (string)$draft->received_at : null,
                'dispatchedAt'=>$draft->dispatched_at !== null
                    ? (string)$draft->dispatched_at : null,
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
