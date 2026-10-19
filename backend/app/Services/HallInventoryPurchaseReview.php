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

/** Read-only purchasing claims; never presents a vendor document as verified. */
final class HallInventoryPurchaseReview {
    public function __construct(private readonly TenantAccess $access) {}
    /** @return array<int,array<string,mixed>> */
    public function recent(User $actor,string $companyId,string $hallId,int $limit=20):array {
        if(!config('inventory_controls.purchase_receipts_enabled'))
            throw new ConflictHttpException('Purchase receipt review is disabled.');
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||$limit<1||$limit>20)
            throw new InvalidArgumentException('Invalid purchase review scope.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Purchase review denied.');
        return DB::table('hall_inventory_purchase_requests as r')
            ->join('hall_inventory_items as i',function($join):void {
                $join->on('i.company_id','=','r.company_id')
                    ->on('i.hall_id','=','r.hall_id')->on('i.id','=','r.item_id');
            })
            ->leftJoin('hall_inventory_purchase_receiving_evidence as pe',function($join):void {
                $join->on('pe.company_id','=','r.company_id')
                    ->on('pe.hall_id','=','r.hall_id')
                    ->on('pe.purchase_request_id','=','r.id');
            })
            ->leftJoin('hall_inventory_purchase_receiving_reviews as pr',function($join):void {
                $join->on('pr.company_id','=','r.company_id')
                    ->on('pr.hall_id','=','r.hall_id')
                    ->on('pr.receiving_evidence_id','=','pe.id');
            })
            ->where('r.company_id',$companyId)->where('r.hall_id',$hallId)
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->limit($limit)
            ->get(['r.id','r.item_id','i.name as item_name','i.unit',
                'r.quantity','r.vendor_name','r.vendor_reference',
                'r.evidence_reference','r.reason','r.created_at','r.requested_by',
                'pe.id as receipt_id','pe.counted_quantity','pe.received_by',
                'pr.id as review_id','pr.reviewed_by'])
            ->map(static fn($r):array=>[
                'id'=>(string)$r->id,'itemId'=>(string)$r->item_id,
                'itemName'=>(string)$r->item_name,'unit'=>(string)$r->unit,
                'quantity'=>(int)$r->quantity,'vendorName'=>(string)$r->vendor_name,
                'vendorReference'=>(string)$r->vendor_reference,
                'evidenceReference'=>(string)$r->evidence_reference,
                'reason'=>(string)$r->reason,'requestedBy'=>(int)$r->requested_by,
                'requestedAt'=>(string)$r->created_at,
                'receivingEvidenceId'=>$r->receipt_id===null?null:(string)$r->receipt_id,
                'countedQuantity'=>$r->counted_quantity===null?null:(int)$r->counted_quantity,
                'receivedBy'=>$r->received_by===null?null:(int)$r->received_by,
                'independentReviewId'=>$r->review_id===null?null:(string)$r->review_id,
                'reviewedBy'=>$r->reviewed_by===null?null:(int)$r->reviewed_by,
                'status'=>$r->review_id!==null
                    ?'INDEPENDENT REVIEW RECORDED / NOT STOCK POSTED'
                    :($r->receipt_id!==null
                        ?'PHYSICAL RECEIVING ASSERTED / AWAITING INDEPENDENT REVIEW'
                        :'UNVERIFIED CLAIM / AWAITING INDEPENDENT RECEIVING'),
                'ownedStockPosted'=>false,'financiallyPosted'=>false,
            ])->all();
    }
}