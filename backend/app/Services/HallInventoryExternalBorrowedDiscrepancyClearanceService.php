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
 * Clears ONLY the independently substantiated partner-received units from
 * borrowed transit. Missing units remain in transit. No liability, journal,
 * payment, stock retirement, owned position or loan closure is created.
 */
final class HallInventoryExternalBorrowedDiscrepancyClearanceService
{
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $transition) {}

    private function gate():void {
        foreach(['loan_drafts_enabled','external_borrowed_intake_enabled',
            'external_borrowed_return_enabled','external_borrowed_discrepancy_enabled',
            'external_borrowed_discrepancy_clearance_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new ConflictHttpException('Partner-confirmed transit clearance is not released.');
    }
    private function state(object $position):array {
        return ['ownedUsable'=>(int)$position->owned_usable,
            'ownedOnLoan'=>(int)$position->owned_on_loan,
            'ownedDamaged'=>(int)$position->owned_damaged,
            'borrowedUsable'=>(int)$position->borrowed_usable,
            'borrowedTransit'=>(int)$position->borrowed_transit,
            'borrowedDamaged'=>(int)$position->borrowed_damaged];
    }
    private function movement(User $actor,string $companyId,string $hallId,string $itemId,
        object $position,int $quantity,string $clearanceId,string $reason):string {
        $before=$this->state($position);
        $after=$this->transition->apply($before,'confirm_borrowed_return',$quantity);
        $revision=(int)$position->revision;
        $updated=DB::table('hall_inventory_positions')->where('company_id',$companyId)
            ->where('hall_id',$hallId)->where('item_id',$itemId)->where('id',$position->id)
            ->where('revision',$revision)->update([
                'borrowed_transit'=>$after['borrowedTransit'],
                'revision'=>$revision+1,'updated_at'=>now()
            ]);
        if($updated!==1)
            throw new ConflictHttpException('Concurrent borrowed transit position revision changed.');
        $id=(string)Str::uuid();
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>$id,'company_id'=>$companyId,'hall_id'=>$hallId,'item_id'=>$itemId,
            'position_id'=>$position->id,'event_kind'=>'confirm_borrowed_return','quantity'=>$quantity,
            'revision_before'=>$revision,'revision_after'=>$revision+1,
            'request_fingerprint'=>hash('sha256',$clearanceId.$itemId.$quantity),
            'reason'=>$reason,
            'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
            'actor_id'=>$actor->getKey()
        ]);
        return $id;
    }
    private function verifyLines(string $companyId,string $clearanceId,object $positive):void {
        $stored=DB::table('hall_inventory_external_in_discrepancy_clearance_lines')
            ->where('company_id',$companyId)->where('clearance_id',$clearanceId)
            ->pluck('quantity','item_id')->map(static fn($n):int=>(int)$n)->all();
        $expected=$positive->mapWithKeys(static fn($l):array=>[
            (string)$l->item_id=>(int)$l->received_quantity])->all();
        ksort($stored,SORT_STRING);ksort($expected,SORT_STRING);
        if($stored!==$expected)
            throw new ConflictHttpException('Partner clearance replay changes the independently received quantities.');
    }
    public function clear(User $actor,string $companyId,string $hallId,string $discrepancyId,
        string $clearanceId):string {
        $this->gate();
        foreach([$companyId,$hallId,$discrepancyId,$clearanceId] as $id)
            if(!Str::isUuid($id))
                throw new InvalidArgumentException('Exact partner receipt clearance UUIDs are required.');
        return DB::transaction(function()use($actor,$companyId,$hallId,$discrepancyId,$clearanceId):string {
            $ref=DB::table('hall_inventory_external_in_discrepancies')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$discrepancyId)->first(['loan_id','batch_id','reported_by']);
            if(!$ref)throw new AuthorizationException('Partner discrepancy is outside this hall.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$ref->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||
                (string)$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Clearance must belong to the original borrowing-hall loan.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current borrowing-hall owner/admin required.');
            $discrepancy=DB::table('hall_inventory_external_in_discrepancies')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$discrepancyId)->lockForUpdate()->first();
            if(!$discrepancy)throw new ConflictHttpException('Partner discrepancy was not found.');
            if((int)$discrepancy->reported_by===(int)$actor->getKey())
                throw new AuthorizationException('Partner-count recorder cannot post the physical clearance.');
            $batch=DB::table('hall_inventory_external_in_return_batches')
                ->where('company_id',$companyId)->where('id',$discrepancy->batch_id)->first();
            if($batch && (int)$batch->dispatched_by===(int)$actor->getKey())
                throw new AuthorizationException('Return dispatcher cannot post the partner receipt clearance.');
            $decision=DB::table('hall_inventory_external_in_discrepancy_decisions')
                ->where('company_id',$companyId)->where('discrepancy_id',$discrepancyId)->first();
            if(!$decision||$decision->decision!=='substantiated')
                throw new ConflictHttpException('Only an independently substantiated partner discrepancy may clear transit.');
            $lines=DB::table('hall_inventory_external_in_discrepancy_lines')
                ->where('company_id',$companyId)->where('discrepancy_id',$discrepancyId)
                ->orderBy('item_id')->get(['item_id','shipped_quantity','received_quantity']);
            if($lines->isEmpty())
                throw new ConflictHttpException('Partner discrepancy has no immutable line evidence.');
            $positive=$lines->filter(static fn($l):bool=>(int)$l->received_quantity>0)->values();
            if($positive->isEmpty())
                throw new ConflictHttpException('The partner received no units to clear from transit.');
            $old=DB::table('hall_inventory_external_in_discrepancy_clearances')
                ->where('company_id',$companyId)->where('discrepancy_id',$discrepancyId)->first();
            if($old) {
                if((string)$old->id!==$clearanceId||
                    (int)$old->cleared_by!==(int)$actor->getKey())
                    throw new ConflictHttpException('Partner discrepancy already has its own immutable clearance.');
                $this->verifyLines($companyId,$old->id,$positive);
                return $clearanceId;
            }
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$discrepancyId,
                (string)$decision->id,$positive->map(static fn($l):array=>[
                    (string)$l->item_id,(int)$l->received_quantity])->all(),
                (int)$actor->getKey()],JSON_THROW_ON_ERROR));
            $reason='Independent partner-signed receipt for discrepancy '.$discrepancyId;
            $movements=[];
            foreach($positive as $line) {
                $itemId=(string)$line->item_id;$quantity=(int)$line->received_quantity;
                $position=DB::table('hall_inventory_positions')->where('company_id',$companyId)
                    ->where('hall_id',$hallId)->where('item_id',$itemId)->lockForUpdate()->first();
                if(!$position||(int)$position->borrowed_transit<$quantity)
                    throw new ConflictHttpException('Borrowed transit cannot cover the partner-confirmed quantity.');
                $movements[]=['itemId'=>$itemId,'quantity'=>$quantity,
                    'movementId'=>$this->movement($actor,$companyId,$hallId,$itemId,
                        $position,$quantity,$clearanceId,$reason)];
            }
            DB::table('hall_inventory_external_in_discrepancy_clearances')->insert([
                'id'=>$clearanceId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'loan_id'=>$loan->id,'discrepancy_id'=>$discrepancyId,
                'decision_id'=>$decision->id,'fingerprint'=>$fingerprint,
                'cleared_by'=>$actor->getKey()
            ]);
            foreach($movements as $movement)
                DB::table('hall_inventory_external_in_discrepancy_clearance_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,'hall_id'=>$hallId,
                    'clearance_id'=>$clearanceId,'item_id'=>$movement['itemId'],
                    'movement_id'=>$movement['movementId'],'quantity'=>$movement['quantity']
                ]);
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,'actor_id'=>$actor->getKey(),
                'action'=>'hall_inventory.external_borrowed_partner_receipt_cleared',
                'entity_type'=>'hall_inventory_external_in_discrepancy_clearance',
                'entity_id'=>$clearanceId,
                'before_values'=>json_encode(['borrowedTransitCleared'=>false],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['discrepancyId'=>$discrepancyId,
                    'receivedQuantities'=>$positive->mapWithKeys(static fn($l):array=>[
                        (string)$l->item_id=>(int)$l->received_quantity])->all(),
                    'missingRemainsInTransit'=>true,'moneyPosted'=>false,
                    'loanClosed'=>false],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['loanId'=>$loan->id,'hallId'=>$hallId,
                    'decisionId'=>$decision->id],JSON_THROW_ON_ERROR)
            ]);
            return $clearanceId;
        },3);
    }
}
