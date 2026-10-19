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
 * Physical stocktake evidence history, not book-stock or variance approval.
 * Read access requires a CURRENT assigned hall manager or owner/admin.
 */
final class HallInventoryStocktakeReview
{
    public function __construct(private readonly TenantAccess $access) {}

    /** @return array<int,array<string,mixed>> */
    public function recent(User $actor,string $companyId,string $hallId,int $limit=20):array
    {
        if(!config('inventory_controls.stocktakes_enabled')) {
            throw new ConflictHttpException('Physical stocktake recording is not released.');
        }
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||$limit<1||$limit>20) {
            throw new InvalidArgumentException('Invalid stocktake history request.');
        }
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$hallId)->where('is_active',true)->first();
        if(!$hall || !Auth::check() ||
           (int)Auth::id()!==(int)$actor->getKey() ||
           !$this->access->canManageHallInventory($actor,$hall)) {
            throw new AuthorizationException('Physical counts are not accessible for this hall.');
        }
        $records=DB::table('hall_inventory_stocktakes')
            ->where('company_id',$companyId)->where('hall_id',$hallId)
            ->orderByDesc('counted_at')->orderByDesc('id')->limit($limit)
            ->get(['id','audit_kind','reason','counted_at','counted_by']);
        if($records->isEmpty())return [];

        $lines=DB::table('hall_inventory_stocktake_lines as sl')
            ->join('hall_inventory_items as item',function($join):void {
                $join->on('item.company_id','=','sl.company_id')
                     ->on('item.hall_id','=','sl.hall_id')
                     ->on('item.id','=','sl.item_id');
            })
            ->where('sl.company_id',$companyId)->where('sl.hall_id',$hallId)
            ->whereIn('sl.stocktake_id',$records->pluck('id')->all())
            ->orderBy('sl.stocktake_id')->orderBy('item.name')
            ->orderBy('sl.item_id')->orderBy('sl.ownership')
            ->get(['sl.stocktake_id','item.name','item.unit','sl.ownership',
                'sl.usable_count','sl.damaged_count'])
            ->groupBy('stocktake_id');

        return $records->map(static fn($record):array=>[
            'id'=>(string)$record->id,
            'auditKind'=>(string)$record->audit_kind,
            'reason'=>(string)$record->reason,
            'countedAt'=>(string)$record->counted_at,
            'countedBy'=>(int)$record->counted_by,
            'evidenceOnly'=>true,
            'adjustmentPosted'=>false,
            'lines'=>($lines->get($record->id)??collect())->map(
                static fn($line):array=>[
                    'name'=>(string)$line->name,
                    'unit'=>(string)$line->unit,
                    'ownership'=>(string)$line->ownership,
                    'usable'=>(int)$line->usable_count,
                    'damaged'=>(int)$line->damaged_count,
                ]
            )->all(),
        ])->all();
    }
}
