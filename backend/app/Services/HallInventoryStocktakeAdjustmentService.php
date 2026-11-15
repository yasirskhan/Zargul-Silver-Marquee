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

/** Two-actor reclassification of an approved stocktake discrepancy. */
final class HallInventoryStocktakeAdjustmentService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate(): void
    {
        foreach (['stocktakes_enabled','stocktake_variance_enabled','position_reports_enabled','stocktake_adjustment_enabled'] as $flag) {
            if (config('inventory_controls.'.$flag) !== true) {
                throw new ConflictHttpException('Authorized stocktake adjustment is not released.');
            }
        }
    }

    private function reason(string $reason): string
    {
        $reason=trim($reason);
        if(mb_strlen($reason)<10||mb_strlen($reason)>500
            ||preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu',$reason)) {
            throw new InvalidArgumentException('A plain adjustment reason of 10-500 characters is required.');
        }
        return $reason;
    }

    private function authorize(User $actor,string $companyId,string $hallId): void
    {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()
            ||!$this->access->canManageHallInventory($actor,$hall)) {
            throw new AuthorizationException('Current hall inventory authority required.');
        }
    }

    public function propose(User $actor,string $companyId,string $hallId,string $stocktakeId,string $adjustmentId,string $reason):array
    {
        $this->gate();
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||!Str::isUuid($stocktakeId)||!Str::isUuid($adjustmentId)) {
            throw new InvalidArgumentException('Invalid stocktake adjustment scope.');
        }
        $reason=$this->reason($reason);
        return DB::transaction(function()use($actor,$companyId,$hallId,$stocktakeId,$adjustmentId,$reason):array{
            $this->authorize($actor,$companyId,$hallId);
            $take=DB::table('hall_inventory_stocktakes')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('id',$stocktakeId)->first();
            if(!$take) throw new AuthorizationException('Stocktake is outside this hall.');
            $lines=DB::table('hall_inventory_stocktake_lines')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('stocktake_id',$stocktakeId)->where('ownership','owned')->get();
            if($lines->count()!==1) throw new ConflictHttpException('Adjustment requires exactly one owned stocktake line.');
            $line=$lines->first();
            $position=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('item_id',$line->item_id)->lockForUpdate()->first();
            if(!$position) throw new ConflictHttpException('Posted position is unavailable for this item.');
            $usableBefore=(int)$position->owned_usable;$damagedBefore=(int)$position->owned_damaged;
            $usableAfter=(int)$line->usable_count;$damagedAfter=(int)$line->damaged_count;
            if($usableAfter===$usableBefore&&$damagedAfter===$damagedBefore) {
                throw new ConflictHttpException('Stocktake has no reclassification difference.');
            }
            if($usableAfter+$damagedAfter!==$usableBefore+$damagedBefore) {
                throw new ConflictHttpException('Only conservation-preserving damaged reclassification is permitted.');
            }
            if(DB::table('hall_inventory_stocktake_adjustments')->where('stocktake_id',$stocktakeId)
                ->where('item_id',$line->item_id)->where('ownership','owned')->exists()) {
                throw new ConflictHttpException('This stocktake line already has an adjustment.');
            }
            $existing=DB::table('hall_inventory_stocktake_adjustments')->where('id',$adjustmentId)->first();
            if($existing) throw new ConflictHttpException('Adjustment request ID was already used.');
            DB::table('hall_inventory_stocktake_adjustments')->insert([
                'id'=>$adjustmentId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'stocktake_id'=>$stocktakeId,'item_id'=>$line->item_id,'ownership'=>'owned',
                'status'=>'proposed','expected_revision'=>(int)$position->revision,
                'usable_before'=>$usableBefore,'damaged_before'=>$damagedBefore,
                'usable_after'=>$usableAfter,'damaged_after'=>$damagedAfter,
                'proposed_by'=>$actor->getKey(),'proposed_at'=>now(),'reason'=>$reason,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                'action'=>'inventory.stocktake_adjustment_proposed','entity_type'=>'hall_inventory_stocktake_adjustment',
                'entity_id'=>$adjustmentId,'before_values'=>null,
                'after_values'=>json_encode(['stocktakeId'=>$stocktakeId,'itemId'=>$line->item_id,
                    'usableBefore'=>$usableBefore,'damagedBefore'=>$damagedBefore,
                    'usableAfter'=>$usableAfter,'damagedAfter'=>$damagedAfter],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['reason'=>$reason],JSON_THROW_ON_ERROR),
            ]);
            return $this->summary($adjustmentId);
        },3);
    }

    public function approve(User $actor,string $companyId,string $hallId,string $adjustmentId,string $reason):array
    {
        $this->gate();
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||!Str::isUuid($adjustmentId)) {
            throw new InvalidArgumentException('Invalid adjustment approval scope.');
        }
        $reason=$this->reason($reason);
        return DB::transaction(function()use($actor,$companyId,$hallId,$adjustmentId,$reason):array{
            $this->authorize($actor,$companyId,$hallId);
            $adjustment=DB::table('hall_inventory_stocktake_adjustments')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('id',$adjustmentId)->lockForUpdate()->first();
            if(!$adjustment) throw new AuthorizationException('Adjustment is outside this hall.');
            if((int)$adjustment->proposed_by===(int)$actor->getKey()) {
                throw new AuthorizationException('The proposing actor cannot approve the same adjustment.');
            }
            if($adjustment->status==='applied') {
                if((int)$adjustment->approved_by===(int)$actor->getKey()&&$adjustment->approval_reason===$reason) {
                    return $this->summary($adjustmentId);
                }
                throw new ConflictHttpException('Adjustment was already applied by another decision.');
            }
            if($adjustment->status!=='proposed') throw new ConflictHttpException('Adjustment is not pending.');
            $position=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('item_id',$adjustment->item_id)->lockForUpdate()->first();
            if(!$position) throw new ConflictHttpException('Posted position disappeared.');
            if((int)$position->revision!==(int)$adjustment->expected_revision
                ||(int)$position->owned_usable!==(int)$adjustment->usable_before
                ||(int)$position->owned_damaged!==(int)$adjustment->damaged_before) {
                throw new ConflictHttpException('Stock changed after adjustment proposal.');
            }
            $usableAfter=(int)$adjustment->usable_after;$damagedAfter=(int)$adjustment->damaged_after;
            if($usableAfter+$damagedAfter!==(int)$adjustment->usable_before+(int)$adjustment->damaged_before) {
                throw new ConflictHttpException('Adjustment would not conserve stock.');
            }
            $movementId=(string)Str::uuid();$before=(int)$position->revision;$after=$before+1;
            DB::table('hall_inventory_positions')->where('company_id',$companyId)
                ->where('id',$position->id)->update(['owned_usable'=>$usableAfter,
                    'owned_damaged'=>$damagedAfter,'revision'=>$after,'updated_at'=>now()]);
            DB::table('hall_inventory_movement_events')->insert([
                'id'=>$movementId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'item_id'=>$adjustment->item_id,'position_id'=>$position->id,
                'event_kind'=>'reclassify_owned_damage','quantity'=>abs($usableAfter-(int)$adjustment->usable_before),
                'revision_before'=>$before,'revision_after'=>$after,
                'request_fingerprint'=>hash('sha256','stocktake-adjustment|'.$adjustmentId),
                'reason'=>$reason,'before_values'=>json_encode(['ownedUsable'=>(int)$adjustment->usable_before,
                    'ownedDamaged'=>(int)$adjustment->damaged_before],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['ownedUsable'=>$usableAfter,'ownedDamaged'=>$damagedAfter],JSON_THROW_ON_ERROR),
                'actor_id'=>$actor->getKey(),'created_at'=>now(),
            ]);
            DB::table('hall_inventory_stocktake_adjustments')->where('company_id',$companyId)
                ->where('id',$adjustmentId)->update(['status'=>'applied','approved_by'=>$actor->getKey(),
                    'approved_at'=>now(),'approval_reason'=>$reason,'movement_id'=>$movementId,'updated_at'=>now()]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                'action'=>'inventory.stocktake_adjustment_applied','entity_type'=>'hall_inventory_stocktake_adjustment',
                'entity_id'=>$adjustmentId,'before_values'=>json_encode(['ownedUsable'=>(int)$adjustment->usable_before,
                    'ownedDamaged'=>(int)$adjustment->damaged_before],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['ownedUsable'=>$usableAfter,'ownedDamaged'=>$damagedAfter,
                    'movementId'=>$movementId,'revision'=>$after],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['reason'=>$reason],JSON_THROW_ON_ERROR),
            ]);
            return $this->summary($adjustmentId);
        },3);
    }

    private function summary(string $id):array
    {
        $row=DB::table('hall_inventory_stocktake_adjustments')->where('id',$id)->first();
        return ['id'=>(string)$row->id,'status'=>(string)$row->status,
            'stocktakeId'=>(string)$row->stocktake_id,'itemId'=>(string)$row->item_id,
            'usableBefore'=>(int)$row->usable_before,'damagedBefore'=>(int)$row->damaged_before,
            'usableAfter'=>(int)$row->usable_after,'damagedAfter'=>(int)$row->damaged_after,
            'movementId'=>$row->movement_id];
    }
}
