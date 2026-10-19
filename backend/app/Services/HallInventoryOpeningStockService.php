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
 * Independently default-disabled opening-owned-stock evidence workflow.
 * Request = immutable physical count assertion, NOT inventory posting.
 * Acceptance requires a DIFFERENT active owner/admin, locks hall then item,
 * and posts one append-only movement + immutable acceptance atomically.
 * No purchases, charges, receipts, loans or financial journal writes.
 */
final class HallInventoryOpeningStockService
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $positions
    ) {}

    public function request(
        User $actor,string $companyId,string $hallId,string $itemId,
        string $stocktakeId,string $requestId,int $quantity,string $reason
    ):string {
        $this->enabled();
        $reason=trim($reason);
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||
            !Str::isUuid($itemId)||!Str::isUuid($stocktakeId)||
            !Str::isUuid($requestId)||$quantity<1||$quantity>100000000||
            mb_strlen($reason)<5||mb_strlen($reason)>500) {
            throw new InvalidArgumentException('Invalid opening stock evidence.');
        }
        $fingerprint=hash('sha256',json_encode([
            'actor'=>$actor->getKey(),'company'=>$companyId,'hall'=>$hallId,
            'item'=>$itemId,'stocktake'=>$stocktakeId,'qty'=>$quantity,
            'reason'=>$reason,
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use(
            $actor,$companyId,$hallId,$itemId,$stocktakeId,$requestId,
            $quantity,$reason,$fingerprint
        ):string{
            $hall=Hall::query()->where('company_id',$companyId)
                ->where('id',$hallId)->where('is_active',true)
                ->lockForUpdate()->first();
            if(!$hall||!Auth::check()||((int)Auth::id()!==(int)$actor->getKey())||
                !$this->access->canManageHallInventory($actor,$hall)) {
                throw new AuthorizationException('Opening stock evidence denied.');
            }
            $item=DB::table('hall_inventory_items')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$itemId)->where('is_active',true)
                ->lockForUpdate()->first(['id']);
            if(!$item){
                throw new AuthorizationException('Catalog item is not in the active hall.');
            }
            $old=DB::table('hall_inventory_opening_requests')
                ->where('id',$requestId)->first();
            if($old){
                if($old->company_id!==$companyId||$old->hall_id!==$hallId||
                    $old->item_id!==$itemId||
                    $old->stocktake_id!==$stocktakeId||
                    (int)$old->requested_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->request_fingerprint,$fingerprint)){
                    throw new ConflictHttpException('Opening request ID conflict.');
                }
                return $requestId;
            }
            if(DB::table('hall_inventory_opening_requests')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('item_id',$itemId)->exists()){
                throw new ConflictHttpException('This hall item already has an opening request.');
            }
            $take=DB::table('hall_inventory_stocktakes')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$stocktakeId)->where('audit_kind','opening')
                ->where('counted_by',$actor->getKey())->first(['id']);
            $count=$take?DB::table('hall_inventory_stocktake_lines')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('stocktake_id',$stocktakeId)->where('item_id',$itemId)
                ->where('ownership','owned')->where('usable_count',$quantity)
                ->where('damaged_count',0)->first(['id']):null;
            if(!$count){
                throw new ConflictHttpException(
                    'Matching witnessed physical opening count is required.'
                );
            }
            DB::table('hall_inventory_opening_requests')->insert([
                'id'=>$requestId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'item_id'=>$itemId,'stocktake_id'=>$stocktakeId,
                'quantity'=>$quantity,'request_fingerprint'=>$fingerprint,
                'reason'=>$reason,'requested_by'=>$actor->getKey(),
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$actor->getKey(),
                'action'=>'hall_inventory.opening_stock_requested',
                'entity_type'=>'hall_inventory_opening_request',
                'entity_id'=>$requestId,
                'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode([
                    'hallId'=>$hallId,'itemId'=>$itemId,'quantity'=>$quantity,
                    'stocktakeId'=>$stocktakeId,'state'=>'AWAITING_INDEPENDENT_ACCEPTANCE',
                ],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['reason'=>$reason],JSON_THROW_ON_ERROR),
            ]);
            return $requestId;
        },3);
    }

    public function accept(User $approver,string $companyId,string $hallId,
        string $requestId):string
    {
        $this->enabled();
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||
            !Str::isUuid($requestId)) {
            throw new InvalidArgumentException('Invalid opening acceptance reference.');
        }
        return DB::transaction(function()use($approver,$companyId,$hallId,$requestId):string{
            $hall=Hall::query()->where('company_id',$companyId)
                ->where('id',$hallId)->where('is_active',true)
                ->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$approver->getKey()||
                !$this->access->canConfigureHall($approver,$hall)) {
                throw new AuthorizationException('Only a separate active owner/admin may accept opening stock.');
            }
            $request=DB::table('hall_inventory_opening_requests')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$requestId)->lockForUpdate()->first();
            if(!$request){
                throw new ConflictHttpException('No matching opening stock evidence.');
            }
            if((int)$request->requested_by===(int)$approver->getKey()){
                throw new AuthorizationException('Requester cannot approve their own stock opening.');
            }
            $old=DB::table('hall_inventory_opening_acceptances')
                ->where('opening_request_id',$requestId)->first();
            if($old){
                if((int)$old->accepted_by!==(int)$approver->getKey()){
                    throw new ConflictHttpException('Opening already accepted independently.');
                }
                return (string)$old->movement_id;
            }
            $item=DB::table('hall_inventory_items')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$request->item_id)->where('is_active',true)
                ->lockForUpdate()->first(['id']);
            if(!$item)throw new AuthorizationException('Original hall item no longer active.');

            // Recheck the immutable physical opening count on acceptance.
            $count=DB::table('hall_inventory_stocktake_lines as sl')
                ->join('hall_inventory_stocktakes as st',function($join):void{
                    $join->on('st.company_id','=','sl.company_id')
                         ->on('st.hall_id','=','sl.hall_id')
                         ->on('st.id','=','sl.stocktake_id');
                })
                ->where('sl.company_id',$companyId)->where('sl.hall_id',$hallId)
                ->where('sl.stocktake_id',$request->stocktake_id)
                ->where('sl.item_id',$request->item_id)
                ->where('sl.ownership','owned')
                ->where('sl.usable_count',$request->quantity)
                ->where('sl.damaged_count',0)
                ->where('st.audit_kind','opening')
                ->where('st.counted_by',$request->requested_by)
                ->first(['sl.id']);
            if(!$count)throw new ConflictHttpException('Opening count evidence is not valid.');

            $position=DB::table('hall_inventory_positions')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('item_id',$request->item_id)->lockForUpdate()->first();
            $zero=[
                'ownedUsable'=>0,'ownedOnLoan'=>0,'ownedDamaged'=>0,
                'borrowedUsable'=>0,'borrowedTransit'=>0,'borrowedDamaged'=>0,
            ];
            if($position){
                $oldBuckets=[
                    'ownedUsable'=>(int)$position->owned_usable,
                    'ownedOnLoan'=>(int)$position->owned_on_loan,
                    'ownedDamaged'=>(int)$position->owned_damaged,
                    'borrowedUsable'=>(int)$position->borrowed_usable,
                    'borrowedTransit'=>(int)$position->borrowed_transit,
                    'borrowedDamaged'=>(int)$position->borrowed_damaged,
                ];
                if((int)$position->revision!==0 || $oldBuckets!==$zero){
                    throw new ConflictHttpException('Stock was already opened or moved.');
                }
                $positionId=(string)$position->id;
            }else{
                $positionId=(string)Str::uuid();
                DB::table('hall_inventory_positions')->insert([
                    'id'=>$positionId,'company_id'=>$companyId,
                    'hall_id'=>$hallId,'item_id'=>$request->item_id,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                $oldBuckets=$zero;
            }
            $after=$this->positions->apply(
                $oldBuckets,'opening_owned',(int)$request->quantity
            );
            DB::table('hall_inventory_positions')
                ->where('id',$positionId)->where('revision',0)->update([
                    'owned_usable'=>$after['ownedUsable'],
                    'revision'=>1,'updated_at'=>now(),
                ]);
            $movementId=(string)Str::uuid();
            DB::table('hall_inventory_movement_events')->insert([
                'id'=>$movementId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'item_id'=>$request->item_id,'position_id'=>$positionId,
                'event_kind'=>'opening_owned','quantity'=>(int)$request->quantity,
                'revision_before'=>0,'revision_after'=>1,
                'request_fingerprint'=>$request->request_fingerprint,
                'reason'=>$request->reason,
                'before_values'=>json_encode($oldBuckets,JSON_THROW_ON_ERROR),
                'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                'actor_id'=>$approver->getKey(),
            ]);
            DB::table('hall_inventory_opening_acceptances')->insert([
                'id'=>(string)Str::uuid(),
                'opening_request_id'=>$requestId,
                'movement_id'=>$movementId,
                'accepted_by'=>$approver->getKey(),
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$approver->getKey(),
                'action'=>'hall_inventory.opening_stock_accepted',
                'entity_type'=>'hall_inventory_opening_request',
                'entity_id'=>$requestId,
                'before_values'=>json_encode([
                    'state'=>'AWAITING_INDEPENDENT_ACCEPTANCE',
                ],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode([
                    'state'=>'OPENING_POSTED','movementId'=>$movementId,
                    'ownedUsable'=>$after['ownedUsable'],
                ],JSON_THROW_ON_ERROR),
                'context'=>json_encode([
                    'reason'=>$request->reason,
                    'requestedBy'=>$request->requested_by,
                ],JSON_THROW_ON_ERROR),
            ]);
            return $movementId;
        },3);
    }

    private function enabled():void
    {
        if(!config('inventory_controls.opening_stock_posting_enabled')) {
            throw new ConflictHttpException(
                'Independent opening stock posting is not released.'
            );
        }
    }
}
