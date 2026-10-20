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

/** Independent owner/admin variance REVIEW only. Never corrects inventory or money. */
final class HallInventoryVarianceReviewService {
    public function __construct(
        private readonly TenantAccess $access,
        private readonly HallInventoryStocktakeVariancePreview $preview
    ) {}
    public function acknowledge(User $reviewer,string $companyId,string $hallId,
        string $stocktakeId,string $ackId,string $reason):string {
        if(!config('inventory_controls.stocktakes_enabled')||
            !config('inventory_controls.position_reports_enabled')||
            !config('inventory_controls.stocktake_variance_enabled'))
            throw new ConflictHttpException('Variance review is disabled.');
        $reason=trim($reason);
        if(!Str::isUuid($companyId)||!Str::isUuid($hallId)||
           !Str::isUuid($stocktakeId)||!Str::isUuid($ackId)||
           mb_strlen($reason)<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Invalid variance review reference.');
        $fingerprint=hash('sha256',json_encode([
            $reviewer->getKey(),$companyId,$hallId,$stocktakeId,$reason
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($reviewer,$companyId,$hallId,$stocktakeId,
            $ackId,$reason,$fingerprint):string {
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$reviewer->getKey()||
                !$this->access->canConfigureHall($reviewer,$hall))
                throw new AuthorizationException('Variance review requires current owner/admin.');
            $take=DB::table('hall_inventory_stocktakes')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('id',$stocktakeId)
                ->lockForUpdate()->first();
            if(!$take)throw new ConflictHttpException('Physical count does not belong to hall.');
            if((int)$take->counted_by===(int)$reviewer->getKey())
                throw new AuthorizationException('Count author cannot independently acknowledge variance.');
            $rows=$this->preview->forStocktake($reviewer,$companyId,$hallId,$stocktakeId);
            if(count($rows)<1||!collect($rows)->contains(fn($row)=>$row['requiresReview']))
                throw new ConflictHttpException('No outstanding physical variance requires review.');
            $existing=DB::table('hall_inventory_variance_review_acknowledgments')
                ->where('stocktake_id',$stocktakeId)->first();
            if($existing) {
                if((string)$existing->id!==$ackId ||
                    (int)$existing->reviewed_by!==(int)$reviewer->getKey()||
                    !hash_equals((string)$existing->request_fingerprint,$fingerprint))
                    throw new ConflictHttpException('Variance has already been reviewed independently.');
                return $ackId;
            }
            DB::table('hall_inventory_variance_review_acknowledgments')->insert([
                'id'=>$ackId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'stocktake_id'=>$stocktakeId,'reviewed_by'=>$reviewer->getKey(),
                'review_reason'=>$reason,'request_fingerprint'=>$fingerprint
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$reviewer->getKey(),
                'action'=>'hall_inventory.stocktake_variance_review_acknowledged',
                'entity_type'=>'hall_inventory_stocktake','entity_id'=>$stocktakeId,
                'before_values'=>json_encode(['reviewed'=>false],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['reviewed'=>true,'adjustmentPosted'=>false],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['reason'=>$reason,'ackId'=>$ackId],JSON_THROW_ON_ERROR)
            ]);
            return $ackId;
        },3);
    }
}
