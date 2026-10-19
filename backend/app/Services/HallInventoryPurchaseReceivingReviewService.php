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
 * Immutable independent review of an earlier physical receiving assertion.
 * A review is not document authenticity, acceptance for stock posting,
 * a payable or verified payment. No inventory balance is changed.
 */
final class HallInventoryPurchaseReceivingReviewService
{
    public function __construct(private readonly TenantAccess $access) {}

    public function attest(User $reviewer, string $companyId, string $hallId,
        string $receiptId, string $reviewId, string $notes):string
    {
        if (!config('inventory_controls.purchase_receipts_enabled')) {
            throw new ConflictHttpException('Purchase receiving review is disabled.');
        }
        $notes=trim($notes);
        if (!Str::isUuid($companyId) || !Str::isUuid($hallId) ||
            !Str::isUuid($receiptId) || !Str::isUuid($reviewId) ||
            mb_strlen($notes)<10 || mb_strlen($notes)>500) {
            throw new InvalidArgumentException('Invalid receiving review.');
        }
        $fingerprint=hash('sha256',json_encode([
            $reviewer->getKey(),$companyId,$hallId,$receiptId,$notes
        ],JSON_THROW_ON_ERROR));

        return DB::transaction(function()use($reviewer,$companyId,$hallId,
            $receiptId,$reviewId,$notes,$fingerprint):string {
            $hall=Hall::query()->where('company_id',$companyId)
                ->where('id',$hallId)->where('is_active',true)
                ->lockForUpdate()->first();
            if (!$hall || !Auth::check() || (int)Auth::id()!==(int)$reviewer->getKey() ||
                !$this->access->canConfigureHall($reviewer,$hall)) {
                throw new AuthorizationException('Only an active owner/admin may review receiving evidence.');
            }
            $receipt=DB::table('hall_inventory_purchase_receiving_evidence')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$receiptId)->lockForUpdate()->first();
            if (!$receipt) {
                throw new ConflictHttpException('Receiving evidence not found in hall.');
            }
            $request=DB::table('hall_inventory_purchase_requests')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$receipt->purchase_request_id)
                ->lockForUpdate()->first();
            if (!$request || $request->item_id!==$receipt->item_id ||
                (int)$receipt->counted_quantity>(int)$request->quantity) {
                throw new ConflictHttpException('Purchase evidence chain is inconsistent.');
            }
            if ((int)$reviewer->getKey()===(int)$receipt->received_by ||
                (int)$reviewer->getKey()===(int)$request->requested_by) {
                throw new AuthorizationException('Review requires a third independent actor.');
            }
            $existing=DB::table('hall_inventory_purchase_receiving_reviews')
                ->where('receiving_evidence_id',$receiptId)->first();
            if ($existing) {
                if ((string)$existing->id!==$reviewId ||
                    (int)$existing->reviewed_by!==(int)$reviewer->getKey() ||
                    !hash_equals((string)$existing->review_fingerprint,$fingerprint)) {
                    throw new ConflictHttpException('Receiving review has conflicting prior decision.');
                }
                return $reviewId;
            }
            if (DB::table('hall_inventory_purchase_receiving_reviews')
                ->where('id',$reviewId)->exists()) {
                throw new ConflictHttpException('Receiving review identifier reused.');
            }
            DB::table('hall_inventory_purchase_receiving_reviews')->insert([
                'id'=>$reviewId,'receiving_evidence_id'=>$receiptId,
                'company_id'=>$companyId,'hall_id'=>$hallId,
                'purchase_request_id'=>$request->id,
                'reviewed_by'=>$reviewer->getKey(),
                'review_notes'=>$notes,'review_fingerprint'=>$fingerprint,
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$reviewer->getKey(),
                'action'=>'hall_inventory.purchase_receiving_reviewed',
                'entity_type'=>'hall_inventory_purchase_receiving_review',
                'entity_id'=>$reviewId,
                'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode([
                    'receiptId'=>$receiptId,'requestId'=>$request->id,
                    'state'=>'REVIEW_ATTESTED_NO_STOCK_POSTED'
                ],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['requester'=>$request->requested_by,
                    'receiver'=>$receipt->received_by],JSON_THROW_ON_ERROR),
            ]);
            return $reviewId;
        },3);
    }
}
