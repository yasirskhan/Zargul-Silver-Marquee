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
 * Atomic, independently reviewed owned-stock acquisition.
 * No payment, accounts payable, inventory valuation or supplier authenticity
 * claim is inferred from evidence. Explicit operator flag stays OFF by default.
 */
final class HallInventoryPurchaseStockAcceptanceService
{
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $positions,
        private readonly HallInventoryPurchaseEvidenceFileService $files) {}

    public function accept(User $approver,string $companyId,string $hallId,
        string $purchaseId,int $expectedPositionRevision):string
    {
        if (!config('inventory_controls.purchase_stock_posting_enabled') ||
            !config('inventory_controls.purchase_receipts_enabled') ||
            !config('inventory_controls.purchase_evidence_upload_enabled'))
            throw new ConflictHttpException('Purchase stock posting is not released.');
        if (!Str::isUuid($companyId)||!Str::isUuid($hallId)||
            !Str::isUuid($purchaseId)||$expectedPositionRevision<0)
            throw new InvalidArgumentException('Invalid purchase stock acceptance.');
        return DB::transaction(function()use($approver,$companyId,$hallId,$purchaseId,$expectedPositionRevision):string {
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if (!$hall||!Auth::check()||(int)Auth::id()!==(int)$approver->getKey()||
                !$this->access->canConfigureHall($approver,$hall))
                throw new AuthorizationException('Purchase stock acceptance denied.');
            $request=DB::table('hall_inventory_purchase_requests')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$purchaseId)->lockForUpdate()->first();
            if (!$request)throw new ConflictHttpException('No valid purchase claim in selected hall.');
            $existing=DB::table('hall_inventory_purchase_stock_acceptances')
                ->where('purchase_request_id',$purchaseId)->first();
            if ($existing) {
                if ((int)$existing->accepted_by!==(int)$approver->getKey())
                    throw new ConflictHttpException('Purchase already posted by another approver.');
                return (string)$existing->movement_id;
            }
            $receipt=DB::table('hall_inventory_purchase_receiving_evidence')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('purchase_request_id',$purchaseId)->lockForUpdate()->first();
            $review=$receipt?DB::table('hall_inventory_purchase_receiving_reviews')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('purchase_request_id',$purchaseId)
                ->where('receiving_evidence_id',$receipt->id)->lockForUpdate()->first():null;
            if (!$receipt || !$review || $request->item_id!==$receipt->item_id ||
                (int)$receipt->counted_quantity<1 ||
                (int)$receipt->counted_quantity>(int)$request->quantity)
                throw new ConflictHttpException('Independent matching receiving evidence required.');
            if ((int)$approver->getKey()!==(int)$review->reviewed_by ||
                (int)$approver->getKey()===(int)$request->requested_by ||
                (int)$approver->getKey()===(int)$receipt->received_by)
                throw new AuthorizationException('Independent reviewer alone may finalize purchase stock.');
            $docs=DB::table('hall_inventory_purchase_evidence_files')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('purchase_request_id',$purchaseId)->get(['id','evidence_kind']);
            if ($docs->count()!==2 ||
                $docs->pluck('evidence_kind')->sort()->values()->all()!==['receiving_photo','supplier_document'])
                throw new ConflictHttpException('Both private purchase and receiving files are required.');
            foreach($docs as $document) {
                $this->files->verify($approver,$companyId,$hallId,$purchaseId,(string)$document->id);
            }
            $item=DB::table('hall_inventory_items')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$request->item_id)->where('is_active',true)
                ->lockForUpdate()->first(['id']);
            if (!$item)throw new ConflictHttpException('Catalog item no longer active.');
            $position=DB::table('hall_inventory_positions')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('item_id',$request->item_id)->lockForUpdate()->first();
            $zero=['ownedUsable'=>0,'ownedOnLoan'=>0,'ownedDamaged'=>0,
                'borrowedUsable'=>0,'borrowedTransit'=>0,'borrowedDamaged'=>0];
            $before=$position?[
                'ownedUsable'=>(int)$position->owned_usable,
                'ownedOnLoan'=>(int)$position->owned_on_loan,
                'ownedDamaged'=>(int)$position->owned_damaged,
                'borrowedUsable'=>(int)$position->borrowed_usable,
                'borrowedTransit'=>(int)$position->borrowed_transit,
                'borrowedDamaged'=>(int)$position->borrowed_damaged,
            ]:$zero;
            $revision=$position?(int)$position->revision:0;
            if ($revision!==$expectedPositionRevision)
                throw new ConflictHttpException('Purchase position revision is stale.');
            $quantity=(int)$receipt->counted_quantity;
            $after=$this->positions->apply($before,'acquire_owned',$quantity);
            $positionId=$position?(string)$position->id:(string)Str::uuid();
            if (!$position) {
                DB::table('hall_inventory_positions')->insert([
                    'id'=>$positionId,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'item_id'=>$request->item_id,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            $changed=DB::table('hall_inventory_positions')
                ->where('id',$positionId)->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('item_id',$request->item_id)
                ->where('revision',$revision)->update([
                    'owned_usable'=>$after['ownedUsable'],
                    'revision'=>$revision+1,'updated_at'=>now(),
                ]);
            if ($changed!==1)throw new ConflictHttpException('Purchase stock CAS conflict.');
            $movementId=(string)Str::uuid();
            DB::table('hall_inventory_movement_events')->insert([
                'id'=>$movementId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'item_id'=>$request->item_id,'position_id'=>$positionId,
                'event_kind'=>'acquire_owned','quantity'=>$quantity,
                'revision_before'=>$revision,'revision_after'=>$revision+1,
                'request_fingerprint'=>(string)$request->request_fingerprint,
                'reason'=>'Independently reviewed purchase receiving '.$purchaseId,
                'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                'actor_id'=>$approver->getKey(),
            ]);
            DB::table('hall_inventory_purchase_stock_acceptances')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'hall_id'=>$hallId,'item_id'=>$request->item_id,
                'purchase_request_id'=>$purchaseId,
                'receiving_evidence_id'=>$receipt->id,'review_id'=>$review->id,
                'movement_id'=>$movementId,'accepted_by'=>$approver->getKey(),
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$approver->getKey(),
                'action'=>'hall_inventory.purchase_stock_accepted',
                'entity_type'=>'hall_inventory_purchase_request','entity_id'=>$purchaseId,
                'before_values'=>json_encode(['revision'=>$revision,'ownedUsable'=>$before['ownedUsable']],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['revision'=>$revision+1,'ownedUsable'=>$after['ownedUsable'],
                    'movementId'=>$movementId,'state'=>'PHYSICAL_STOCK_POSTED_NO_FINANCIAL_ENTRY'],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['receiptId'=>$receipt->id,'reviewId'=>$review->id],JSON_THROW_ON_ERROR),
            ]);
            return $movementId;
        },3);
    }
}
