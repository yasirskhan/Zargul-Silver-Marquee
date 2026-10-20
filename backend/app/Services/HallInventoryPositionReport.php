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
 * Read-only hall-level BOOK inventory from genuine posted revisions.
 * A catalog row without a movement is not stock. Borrowed units are
 * never included in the owned replenishment minimum.
 */
final class HallInventoryPositionReport
{
    public function __construct(private readonly TenantAccess $access) {}

    /** @return array<int,array<string,mixed>> */
    public function recent(User $actor,string $companyId,string $hallId,int $limit=100):array
    {
        if(!config('inventory_controls.catalog_enabled') ||
            !config('inventory_controls.position_reports_enabled'))
            throw new ConflictHttpException('Inventory book-balance reporting is disabled.');
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId) ||
            $limit<1||$limit>100)
            throw new InvalidArgumentException('Invalid hall inventory reporting scope.');

        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$hallId)->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Hall inventory report denied.');

        $lastMovement=DB::table('hall_inventory_movement_events')
            ->where('company_id',$companyId)->where('hall_id',$hallId)
            ->select('company_id','hall_id','item_id')
            ->selectRaw('MAX(revision_after) AS latest_revision')
            ->groupBy('company_id','hall_id','item_id');

        return DB::table('hall_inventory_items as i')
            ->leftJoin('hall_inventory_positions as p',function($join):void{
                $join->on('p.company_id','=','i.company_id')
                    ->on('p.hall_id','=','i.hall_id')
                    ->on('p.item_id','=','i.id');
            })
            ->leftJoinSub($lastMovement,'m',function($join):void{
                $join->on('m.company_id','=','i.company_id')
                    ->on('m.hall_id','=','i.hall_id')
                    ->on('m.item_id','=','i.id');
            })
            ->where('i.company_id',$companyId)->where('i.hall_id',$hallId)
            ->where('i.is_active',true)->orderBy('i.name')->orderBy('i.id')
            ->limit($limit)
            ->get(['i.id','i.name','i.category','i.unit','i.minimum_quantity',
                'p.id as position_id','p.revision','p.owned_usable',
                'p.owned_on_loan','p.owned_damaged','p.borrowed_usable',
                'p.borrowed_transit','p.borrowed_damaged',
                'm.latest_revision'])
            ->map(static function($r):array {
                $hasPosition=$r->position_id!==null;
                $posted=$hasPosition&&(int)$r->revision>0&&
                    $r->latest_revision!==null&&
                    (int)$r->latest_revision===(int)$r->revision;
                $status=!$hasPosition || ((int)$r->revision===0 && $r->latest_revision===null)
                    ? 'NO_POSTED_MOVEMENT'
                    : (!$posted?'RECONCILIATION_REQUIRED':
                        ((int)$r->minimum_quantity>0 &&
                         (int)$r->owned_usable<(int)$r->minimum_quantity
                            ?'BELOW_MINIMUM':'POSTED_BOOK_BALANCE'));
                return [
                    'itemId'=>(string)$r->id,'name'=>(string)$r->name,
                    'category'=>(string)$r->category,'unit'=>(string)$r->unit,
                    'minimum'=>(int)$r->minimum_quantity,
                    'ownedUsable'=>$posted?(int)$r->owned_usable:null,
                    'ownedOnLoan'=>$posted?(int)$r->owned_on_loan:null,
                    'ownedDamaged'=>$posted?(int)$r->owned_damaged:null,
                    'borrowedUsable'=>$posted?(int)$r->borrowed_usable:null,
                    'borrowedTransit'=>$posted?(int)$r->borrowed_transit:null,
                    'borrowedDamaged'=>$posted?(int)$r->borrowed_damaged:null,
                    'positionRevision'=>$posted?(int)$r->revision:null,
                    'status'=>$status,'needsReplenishment'=>$status==='BELOW_MINIMUM',
                    'isPhysicalCount'=>false,'isFinancialValuation'=>false,
                ];
            })->all();
    }
}
