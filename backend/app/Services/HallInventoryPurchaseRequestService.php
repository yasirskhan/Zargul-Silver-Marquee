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

final class HallInventoryPurchaseRequestService {
    public function __construct(private readonly TenantAccess $access) {}
    // A purchase claim is NOT independent receiving evidence or inventory posting.
    public function request(User $actor,string $companyId,string $hallId,string $itemId,
        string $requestId,int $quantity,string $vendor,string $reference,
        string $evidence,string $reason):string {
        if(!config('inventory_controls.purchase_receipts_enabled'))
            throw new ConflictHttpException('Purchase receipt workflow is disabled.');
        $vendor=trim($vendor); $reference=trim($reference);
        $evidence=trim($evidence); $reason=trim($reason);
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||!Str::isUuid($itemId)||
            !Str::isUuid($requestId)||$quantity<1||$quantity>100000000||
            mb_strlen($vendor)<2||mb_strlen($vendor)>200||
            mb_strlen($reference)<2||mb_strlen($reference)>160||
            mb_strlen($evidence)<8||mb_strlen($evidence)>300||
            mb_strlen($reason)<5||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Invalid purchase receipt claim.');
        $fingerprint=hash('sha256',json_encode([$actor->getKey(),$companyId,$hallId,
            $itemId,$quantity,$vendor,$reference,$evidence,$reason],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$companyId,$hallId,$itemId,
            $requestId,$quantity,$vendor,$reference,$evidence,$reason,$fingerprint):string {
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canManageHallInventory($actor,$hall))
                throw new AuthorizationException('Purchase claim denied.');
            $item=DB::table('hall_inventory_items')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('id',$itemId)->where('is_active',true)
                ->lockForUpdate()->first(['id']);
            if(!$item)throw new AuthorizationException('Item does not belong to active hall.');
            $old=DB::table('hall_inventory_purchase_requests')->where('id',$requestId)->first();
            if($old){
                if($old->company_id!==$companyId||$old->hall_id!==$hallId||
                    $old->item_id!==$itemId||(int)$old->requested_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->request_fingerprint,$fingerprint))
                    throw new ConflictHttpException('Purchase request replay conflict.');
                return $requestId;
            }
            DB::table('hall_inventory_purchase_requests')->insert([
                'id'=>$requestId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'item_id'=>$itemId,'quantity'=>$quantity,'vendor_name'=>$vendor,
                'vendor_reference'=>$reference,'evidence_reference'=>$evidence,
                'reason'=>$reason,'request_fingerprint'=>$fingerprint,
                'requested_by'=>$actor->getKey()
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.purchase_requested',
                'entity_type'=>'hall_inventory_purchase_request','entity_id'=>$requestId,
                'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['hallId'=>$hallId,'itemId'=>$itemId,
                    'quantity'=>$quantity,'state'=>'PENDING_RECEIVING'],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['reason'=>$reason],JSON_THROW_ON_ERROR)
            ]);
            return $requestId;
        },3);
    }
}