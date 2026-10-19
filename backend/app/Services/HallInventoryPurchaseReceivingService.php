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
 * A separate staff member records a physical receiving ASSERTION. This is
 * not verified supplier payment, accepted inventory or an owned-stock movement.
 */
final class HallInventoryPurchaseReceivingService
{
    public function __construct(private readonly TenantAccess $access) {}

    public function record(
        User $receiver, string $companyId, string $hallId,
        string $requestId, string $receiptId, int $countedQuantity,
        string $physicalEvidenceReference, string $conditionNotes
    ): string {
        if (!config('inventory_controls.purchase_receipts_enabled')) {
            throw new ConflictHttpException('Purchase receiving is disabled.');
        }
        $physicalEvidenceReference = trim($physicalEvidenceReference);
        $conditionNotes = trim($conditionNotes);
        if (!Str::isUuid($companyId) || !Str::isUuid($hallId) ||
            !Str::isUuid($requestId) || !Str::isUuid($receiptId) ||
            $countedQuantity < 1 || $countedQuantity > 100000000 ||
            mb_strlen($physicalEvidenceReference) < 8 ||
            mb_strlen($physicalEvidenceReference) > 300 ||
            mb_strlen($conditionNotes) < 5 || mb_strlen($conditionNotes) > 500) {
            throw new InvalidArgumentException('Invalid purchase physical receiving assertion.');
        }
        $fingerprint = hash('sha256',json_encode([
            $receiver->getKey(), $companyId, $hallId, $requestId,
            $countedQuantity, $physicalEvidenceReference, $conditionNotes
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function () use (
            $receiver,$companyId,$hallId,$requestId,$receiptId,
            $countedQuantity,$physicalEvidenceReference,$conditionNotes,$fingerprint
        ):string {
            $hall = Hall::query()->where('company_id',$companyId)
                ->where('id',$hallId)->where('is_active',true)
                ->lockForUpdate()->first();
            if (!$hall || !Auth::check() || (int)Auth::id() !== (int)$receiver->getKey() ||
                !$this->access->canManageHallInventory($receiver,$hall)) {
                throw new AuthorizationException('Purchase physical receiving denied.');
            }
            $request = DB::table('hall_inventory_purchase_requests')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$requestId)->lockForUpdate()->first();
            if (!$request) {
                throw new ConflictHttpException('Original purchase claim not found in hall.');
            }
            if ((int)$request->requested_by === (int)$receiver->getKey()) {
                throw new AuthorizationException('Purchase requester cannot witness their own receipt.');
            }
            $item = DB::table('hall_inventory_items')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$request->item_id)->where('is_active',true)
                ->lockForUpdate()->first(['id']);
            if (!$item) {
                throw new ConflictHttpException('Purchase catalog item is not active.');
            }
            if ($countedQuantity > (int)$request->quantity) {
                throw new ConflictHttpException('Received count exceeds requested quantity.');
            }
            $existing = DB::table('hall_inventory_purchase_receiving_evidence')
                ->where('purchase_request_id',$requestId)->first();
            if ($existing) {
                if ((string)$existing->id !== $receiptId ||
                    (int)$existing->received_by !== (int)$receiver->getKey() ||
                    !hash_equals((string)$existing->receipt_fingerprint,$fingerprint)) {
                    throw new ConflictHttpException('Receiving already recorded with different evidence.');
                }
                return $receiptId;
            }
            if (DB::table('hall_inventory_purchase_receiving_evidence')
                ->where('id',$receiptId)->exists()) {
                throw new ConflictHttpException('Receiving identifier already used.');
            }
            DB::table('hall_inventory_purchase_receiving_evidence')->insert([
                'id'=>$receiptId,'purchase_request_id'=>$requestId,
                'company_id'=>$companyId,'hall_id'=>$hallId,
                'item_id'=>$request->item_id,'counted_quantity'=>$countedQuantity,
                'physical_evidence_reference'=>$physicalEvidenceReference,
                'condition_notes'=>$conditionNotes,
                'receipt_fingerprint'=>$fingerprint,'received_by'=>$receiver->getKey()
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$receiver->getKey(),
                'action'=>'hall_inventory.purchase_receiving_asserted',
                'entity_type'=>'hall_inventory_purchase_receiving_evidence',
                'entity_id'=>$receiptId,
                'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode([
                    'requestId'=>$requestId,'itemId'=>$request->item_id,
                    'countedQuantity'=>$countedQuantity,
                    'state'=>'AWAITING_INDEPENDENT_ACCEPTANCE_NO_STOCK_POSTED'
                ],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['requester'=>$request->requested_by],JSON_THROW_ON_ERROR)
            ]);
            return $receiptId;
        },3);
    }
}
