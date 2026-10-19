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
 * Read-only inventory loan DRAFT review, strictly by originating/owning hall.
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
            ->leftJoin('hall_inventory_external_partners as p', function ($join):void {
                $join->on('p.id','=','d.external_partner_id')
                    ->on('p.company_id','=','d.company_id');
            })
            ->where('d.company_id',$companyId)
            ->where(function ($query) use ($hallId):void {
                $query->where('d.lending_hall_id',$hallId)
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
                'p.name as external_partner_name',
            ]);
        if($drafts->isEmpty())return [];

        $lines=DB::table('hall_inventory_loan_draft_lines')
            ->where('company_id',$companyId)
            ->whereIn('loan_id',$drafts->pluck('id')->all())
            ->orderBy('loan_id')->orderBy('item_name_snapshot')->orderBy('item_id')
            ->get(['loan_id','item_name_snapshot','unit_snapshot','requested_quantity'])
            ->groupBy('loan_id');

        return $drafts->map(static function ($draft) use ($lines):array {
            return [
                'id'=>(string)$draft->id,
                'direction'=>(string)$draft->direction,
                'lendingHallId'=>$draft->lending_hall_id,
                'borrowingHallId'=>$draft->borrowing_hall_id,
                'externalPartnerName'=>$draft->external_partner_name,
                'expectedReturnDate'=>(string)$draft->expected_return_date,
                'purpose'=>(string)$draft->purpose,
                'createdAt'=>(string)$draft->created_at,
                'status'=>'draft_only',
                'stockMoved'=>false,
                'lines'=>($lines->get($draft->id)??collect())->map(
                    static fn($line):array=>[
                        'name'=>(string)$line->item_name_snapshot,
                        'unit'=>(string)$line->unit_snapshot,
                        'quantity'=>(int)$line->requested_quantity,
                    ]
                )->all(),
            ];
        })->all();
    }
}
