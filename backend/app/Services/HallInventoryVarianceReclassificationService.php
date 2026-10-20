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

/** Authorized condition-only stocktake reconciliation; never writes finance or resolves loss. */
final class HallInventoryVarianceReclassificationService {
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $transition) {}

    public function reconcile(User $actor,string $companyId,string $hallId,
        string $stocktakeId,string $lineId,string $reason):string {
        if(!config('inventory_controls.stocktakes_enabled') ||
            !config('inventory_controls.position_reports_enabled') ||
            !config('inventory_controls.stocktake_variance_enabled') ||
            !config('inventory_controls.variance_reclassification_enabled'))
            throw new ConflictHttpException('Independent condition reconciliation is disabled.');
        $reason=trim($reason);
        foreach([$companyId,$hallId,$stocktakeId,$lineId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Invalid condition reconciliation scope.');
        if(mb_strlen($reason)<10 || mb_strlen($reason)>500)
            throw new InvalidArgumentException('A detailed condition reason (10-500 characters) is required.');
        return DB::transaction(function()use($actor,$companyId,$hallId,$stocktakeId,$lineId,$reason):string {
            // Same lock order as physical count: hall, stocktake, item, position.
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall || !Auth::check() || (int)Auth::id()!==(int)$actor->getKey() ||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current owner/admin required.');
            $take=DB::table('hall_inventory_stocktakes')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('id',$stocktakeId)->lockForUpdate()->first();
            if(!$take)throw new ConflictHttpException('Count is outside the selected hall.');
            if((int)$take->counted_by===(int)$actor->getKey())
                throw new AuthorizationException('Physical counter cannot adjudicate their own count.');
            $ack=DB::table('hall_inventory_variance_review_acknowledgments')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('stocktake_id',$stocktakeId)->lockForUpdate()->first();
            if(!$ack || (int)$ack->reviewed_by!==(int)$actor->getKey())
                throw new AuthorizationException('A matching independent review is required.');
            $line=DB::table('hall_inventory_stocktake_lines')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('stocktake_id',$stocktakeId)
                ->where('id',$lineId)->lockForUpdate()->first();
            if(!$line || $line->ownership!=='owned')
                throw new ConflictHttpException('Only owned physical stock can be condition reclassified.');
            $fingerprint=hash('sha256',json_encode([
                (int)$actor->getKey(),$companyId,$hallId,$stocktakeId,$lineId,$reason
            ],JSON_THROW_ON_ERROR));
            $existing=DB::table('hall_inventory_variance_reclassifications')
                ->where('stocktake_line_id',$lineId)->first();
            if($existing) {
                if((int)$existing->adjudicated_by!==(int)$actor->getKey() ||
                    !hash_equals((string)$existing->request_fingerprint,$fingerprint))
                    throw new ConflictHttpException('Count line already adjudicated.');
                return (string)$existing->movement_id;
            }
            $snapshot=DB::table('hall_inventory_stocktake_book_snapshots')
                ->where('stocktake_line_id',$lineId)->first();
            if(!$snapshot || $snapshot->book_state!=='POSTED_BOOK_SNAPSHOT' ||
                $snapshot->book_revision===null)
                throw new ConflictHttpException('Verified immutable count-time book baseline required.');
            $item=DB::table('hall_inventory_items')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('id',$line->item_id)
                ->where('is_active',true)->lockForUpdate()->first(['id']);
            if(!$item)throw new ConflictHttpException('Counted item is inactive or foreign.');
            $pos=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('item_id',$line->item_id)->lockForUpdate()->first();
            if(!$pos || (int)$pos->revision!==(int)$snapshot->book_revision ||
                (int)$pos->owned_usable!==(int)$snapshot->book_usable ||
                (int)$pos->owned_damaged!==(int)$snapshot->book_damaged)
                throw new ConflictHttpException('Book moved after count; a fresh count is required.');
            if((int)$pos->owned_on_loan!==0)
                throw new ConflictHttpException('Outstanding owned loans require independent custody reconciliation.');
            $before=['ownedUsable'=>(int)$pos->owned_usable,
                'ownedOnLoan'=>(int)$pos->owned_on_loan,
                'ownedDamaged'=>(int)$pos->owned_damaged,
                'borrowedUsable'=>(int)$pos->borrowed_usable,
                'borrowedTransit'=>(int)$pos->borrowed_transit,
                'borrowedDamaged'=>(int)$pos->borrowed_damaged];
            $rows=DB::table('hall_inventory_movement_events')->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('item_id',$line->item_id)
                ->where('position_id',$pos->id)->orderBy('revision_after')->get();
            if($rows->count()!==(int)$pos->revision)throw new ConflictHttpException('Missing inventory revisions.');
            $normalize=static function(array $v):array {ksort($v,SORT_STRING);return $v;};
            $cursor=$normalize(['ownedUsable'=>0,'ownedOnLoan'=>0,'ownedDamaged'=>0,
                'borrowedUsable'=>0,'borrowedTransit'=>0,'borrowedDamaged'=>0]);
            foreach($rows as $i=>$event) {
                if((int)$event->revision_before!==$i ||
                    (int)$event->revision_after!==$i+1 ||
                    !is_array(json_decode((string)$event->before_values,true)) ||
                    $normalize(json_decode((string)$event->before_values,true))!==$cursor)
                    throw new ConflictHttpException('Broken inventory revision chain.');
                $next=json_decode((string)$event->after_values,true);
                if(!is_array($next))throw new ConflictHttpException('Invalid inventory journal snapshot.');
                $cursor=$normalize($next);
            }
            if($cursor!==$normalize($before))throw new ConflictHttpException('Book differs from movement journal.');
            $usable=(int)$line->usable_count; $damaged=(int)$line->damaged_count;
            if($usable+$damaged!==$before['ownedUsable']+$before['ownedDamaged'] ||
                ($usable===$before['ownedUsable'] && $damaged===$before['ownedDamaged']))
                throw new ConflictHttpException('Missing/surplus stock needs separate liability adjudication.');
            $kind=$usable<$before['ownedUsable']?'reclassify_owned_damage':'restore_owned_usable';
            $quantity=abs($usable-$before['ownedUsable']);
            if($quantity<1 || $quantity>100000000)
                throw new ConflictHttpException('Invalid condition quantity.');
            $after=$this->transition->apply($before,$kind,$quantity);
            if($after['ownedUsable']!==$usable || $after['ownedDamaged']!==$damaged)
                throw new ConflictHttpException('Condition movement does not match owned count.');
            $rev=(int)$pos->revision;
            $changed=DB::table('hall_inventory_positions')
                ->where('id',$pos->id)->where('company_id',$companyId)
                ->where('hall_id',$hallId)->where('item_id',$line->item_id)
                ->where('revision',$rev)->update([
                    'owned_usable'=>$after['ownedUsable'],'owned_damaged'=>$after['ownedDamaged'],
                    'revision'=>$rev+1,'updated_at'=>now()
                ]);
            if($changed!==1)throw new ConflictHttpException('Concurrent inventory adjustment detected.');
            $movementId=(string)Str::uuid();
            DB::table('hall_inventory_movement_events')->insert([
                'id'=>$movementId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'item_id'=>$line->item_id,'position_id'=>$pos->id,
                'event_kind'=>$kind,'quantity'=>$quantity,'revision_before'=>$rev,
                'revision_after'=>$rev+1,'request_fingerprint'=>$fingerprint,
                'reason'=>'Independently reviewed condition: '.$reason,
                'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                'actor_id'=>$actor->getKey()
            ]);
            $decisionId=(string)Str::uuid();
            DB::table('hall_inventory_variance_reclassifications')->insert([
                'id'=>$decisionId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'stocktake_id'=>$stocktakeId,'stocktake_line_id'=>$lineId,
                'acknowledgment_id'=>$ack->id,'position_id'=>$pos->id,
                'movement_id'=>$movementId,'event_kind'=>$kind,'quantity'=>$quantity,
                'book_revision'=>$rev,'request_fingerprint'=>$fingerprint,
                'reason'=>$reason,'adjudicated_by'=>$actor->getKey()
            ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$actor->getKey(),
                'action'=>'hall_inventory.stocktake_condition_reclassified',
                'entity_type'=>'hall_inventory_variance_reclassification','entity_id'=>$decisionId,
                'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
                'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
                'context'=>json_encode(['reason'=>$reason,'stocktakeId'=>$stocktakeId,
                    'movementId'=>$movementId,'totalOwnedConserved'=>true,
                    'financialPosting'=>false,'missingStockSettled'=>false],JSON_THROW_ON_ERROR)
            ]);
            return $movementId;
        },3);
    }
}
