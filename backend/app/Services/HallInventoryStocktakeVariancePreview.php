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
 * Read-only variance against a book snapshot captured IN the original
 * stocktake submission. Never compares an old count against current stock.
 * No write-off, posting, financial adjustment or closure authority.
 */
final class HallInventoryStocktakeVariancePreview
{
    public function __construct(private readonly TenantAccess $access) {}

    /** @return array<int,array<string,mixed>> */
    public function forStocktake(User $actor,string $companyId,string $hallId,string $stocktakeId):array
    {
        if(!config('inventory_controls.stocktakes_enabled') ||
            !config('inventory_controls.position_reports_enabled') ||
            !config('inventory_controls.stocktake_variance_enabled'))
            throw new ConflictHttpException('Physical variance preview is disabled.');
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||!Str::isUuid($stocktakeId))
            throw new InvalidArgumentException('Invalid physical variance scope.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Stocktake variance viewing denied.');
        $take=DB::table('hall_inventory_stocktakes')
            ->where('company_id',$companyId)->where('hall_id',$hallId)
            ->where('id',$stocktakeId)->first(['id']);
        if(!$take)throw new ConflictHttpException('Physical count not recorded in this hall.');
        return DB::table('hall_inventory_stocktake_lines as l')
            ->join('hall_inventory_items as i',function($j):void {
                $j->on('i.company_id','=','l.company_id')
                    ->on('i.hall_id','=','l.hall_id')->on('i.id','=','l.item_id');
            })
            ->leftJoin('hall_inventory_stocktake_book_snapshots as s',
                's.stocktake_line_id','=','l.id')
            ->where('l.company_id',$companyId)->where('l.hall_id',$hallId)
            ->where('l.stocktake_id',$stocktakeId)
            ->orderBy('i.name')->orderBy('l.ownership')->orderBy('l.item_id')
            ->get(['l.id','l.item_id','i.name','i.unit','l.ownership',
                'l.usable_count','l.damaged_count','s.book_revision','s.book_usable',
                's.book_damaged','s.away_count','s.book_state'])
            ->map(static function($row):array {
                $trusted=$row->book_state==='POSTED_BOOK_SNAPSHOT';
                $usable=$trusted?(int)$row->book_usable:null;
                $damaged=$trusted?(int)$row->book_damaged:null;
                return [
                    'itemId'=>(string)$row->item_id,
                    'name'=>(string)$row->name,'unit'=>(string)$row->unit,
                    'ownership'=>(string)$row->ownership,
                    'countedUsable'=>(int)$row->usable_count,
                    'countedDamaged'=>(int)$row->damaged_count,
                    'bookUsable'=>$usable,'bookDamaged'=>$damaged,
                    'awayCount'=>$trusted?(int)$row->away_count:null,
                    'bookRevision'=>$trusted?(int)$row->book_revision:null,
                    'usableVariance'=>$trusted?(int)$row->usable_count-$usable:null,
                    'damagedVariance'=>$trusted?(int)$row->damaged_count-$damaged:null,
                    'status'=>$row->book_state===null?'HISTORICAL_NO_SNAPSHOT':
                        (string)$row->book_state,
                    'requiresReview'=>!$trusted || (int)$row->usable_count!==$usable ||
                        (int)$row->damaged_count!==$damaged,
                    'adjustmentPosted'=>false,'financialPosting'=>false,
                ];
            })->all();
    }
}
