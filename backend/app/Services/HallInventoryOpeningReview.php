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
 * Read-only, company AND hall-scoped opening-count evidence history.
 * Not a stock-approval API and never a substitute for independent witness.
 */
final class HallInventoryOpeningReview
{
    public function __construct(private readonly TenantAccess $access) {}

    /** @return array<int,array<string,mixed>> */
    public function recent(User $actor,string $companyId,string $hallId,
        int $limit=20):array
    {
        if(!config('inventory_controls.opening_stock_posting_enabled')) {
            throw new ConflictHttpException('Opening stock reviews are not released.');
        }
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||
            $limit<1||$limit>20) {
            throw new InvalidArgumentException('Invalid opening stock review scope.');
        }
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$hallId)->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall)) {
            throw new AuthorizationException('Opening stock review denied.');
        }
        $rows=DB::table('hall_inventory_opening_requests as r')
            ->join('hall_inventory_items as i',function($join):void{
                $join->on('i.company_id','=','r.company_id')
                     ->on('i.hall_id','=','r.hall_id')
                     ->on('i.id','=','r.item_id');
            })
            ->leftJoin('hall_inventory_opening_acceptances as a',
                'a.opening_request_id','=','r.id')
            ->where('r.company_id',$companyId)->where('r.hall_id',$hallId)
            ->orderByDesc('r.created_at')->orderByDesc('r.id')
            ->limit($limit)->get([
                'r.id','r.item_id','i.name as item_name','i.unit',
                'r.quantity','r.stocktake_id','r.reason','r.created_at',
                'r.requested_by','a.accepted_by','a.movement_id',
            ]);
        return $rows->map(static fn($row):array=>[
            'id'=>(string)$row->id,
            'itemId'=>(string)$row->item_id,
            'itemName'=>(string)$row->item_name,
            'unit'=>(string)$row->unit,
            'quantity'=>(int)$row->quantity,
            'physicalCountId'=>(string)$row->stocktake_id,
            'reason'=>(string)$row->reason,
            'requestedAt'=>(string)$row->created_at,
            'requestedBy'=>(int)$row->requested_by,
            'acceptedBy'=>$row->accepted_by===null?null:(int)$row->accepted_by,
            'movementId'=>$row->movement_id===null?null:(string)$row->movement_id,
            'status'=>$row->movement_id===null
                ?'PENDING INDEPENDENT REVIEW':'ACCEPTED / BOOKED',
            'financiallyPosted'=>false,
        ])->all();
    }
}
