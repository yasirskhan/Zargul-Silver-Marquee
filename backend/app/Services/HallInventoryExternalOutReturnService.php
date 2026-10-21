<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Partner to source hall partial physical returns, then independent
 * owner condition review. Unresolved custody stays ON LOAN. NO money,
 * claims, deductions, invoices or clean/non-clean loan closure. */
final class HallInventoryExternalOutReturnService {
    public function __construct(private readonly TenantAccess $access,
        private readonly HallInventoryPositionTransition $transition) {}
    private function gate():void {
        if(!config('inventory_controls.loan_drafts_enabled')||
           !config('inventory_controls.external_out_dispatch_enabled')||
           !config('inventory_controls.external_out_return_enabled'))
            throw new ConflictHttpException('Outside owned-stock return workflow is not released.');
    }
    private function ids(string ...$ids):void {
        foreach($ids as $id)if(!Str::isUuid($id))
            throw new InvalidArgumentException('Exact outside partner return UUID required.');
    }
    private function hall(User $actor,string $companyId,string $hallId,bool $owner):void {
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$hallId)->where('is_active',true)->lockForUpdate()->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
           !($owner?$this->access->canConfigureHall($actor,$hall):
               $this->access->canManageHallInventory($actor,$hall)))
            throw new AuthorizationException('Current exact-hall authorized outside return actor required.');
    }
    private function evidence(UploadedFile $file):array {
        $path=$file->getRealPath();$size=$file->getSize();
        if(!$file->isValid()||!$path||!is_file($path)||!is_int($size)||
            $size<1||$size>5242880||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/png','image/jpeg'],true))
            throw new InvalidArgumentException('Partner signed outside return photo/PDF, max 5 MB, required.');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Signed outside return evidence unreadable.');
        return [$bytes,$size,hash('sha256',$bytes)];
    }
    private function verify(object $row):void {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->evidence_storage_path))
            throw new ConflictHttpException('Original signed partner return evidence missing.');
        $bytes=$disk->get((string)$row->evidence_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
            !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Original signed partner return evidence failed integrity.');
    }
    private function position(object $p):array {
        return ['ownedUsable'=>(int)$p->owned_usable,'ownedOnLoan'=>(int)$p->owned_on_loan,
            'ownedDamaged'=>(int)$p->owned_damaged,'borrowedUsable'=>(int)$p->borrowed_usable,
            'borrowedTransit'=>(int)$p->borrowed_transit,'borrowedDamaged'=>(int)$p->borrowed_damaged];
    }
    private function post(User $owner,string $companyId,string $hallId,string $itemId,
        object $position,string $kind,int $qty,string $batchId,string $hash):array {
        if($qty===0)return [$position,null];
        $before=$this->position($position);
        $after=$this->transition->apply($before,$kind,$qty);
        $rev=(int)$position->revision;
        $n=DB::table('hall_inventory_positions')->where('company_id',$companyId)
            ->where('hall_id',$hallId)->where('item_id',$itemId)
            ->where('id',$position->id)->where('revision',$rev)->update([
                'owned_usable'=>$after['ownedUsable'],
                'owned_on_loan'=>$after['ownedOnLoan'],
                'owned_damaged'=>$after['ownedDamaged'],
                'revision'=>$rev+1,'updated_at'=>now()
            ]);
        if($n!==1)throw new ConflictHttpException('Outside return balance changed concurrently.');
        $movementId=(string)Str::uuid();
        DB::table('hall_inventory_movement_events')->insert([
            'id'=>$movementId,'company_id'=>$companyId,'hall_id'=>$hallId,
            'item_id'=>$itemId,'position_id'=>$position->id,'event_kind'=>$kind,
            'quantity'=>$qty,'revision_before'=>$rev,'revision_after'=>$rev+1,
            'request_fingerprint'=>hash('sha256',$batchId.$itemId.$kind.$hash),
            'reason'=>'Independently assessed outside owned return '.$batchId,
            'before_values'=>json_encode($before,JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($after,JSON_THROW_ON_ERROR),
            'actor_id'=>$owner->getKey()
        ]);
        $position->owned_usable=$after['ownedUsable'];
        $position->owned_on_loan=$after['ownedOnLoan'];
        $position->owned_damaged=$after['ownedDamaged'];
        $position->revision=$rev+1;
        return [$position,$movementId];
    }
    /** @param array<string,int> $quantities sparse positive ACTUAL received items */
    public function receive(User $witness,string $companyId,string $hallId,string $loanId,
        string $receiptId,array $quantities,UploadedFile $signedEvidence):string {
        $this->gate();$this->ids($companyId,$hallId,$loanId,$receiptId);
        [$bytes,$size,$hash]=$this->evidence($signedEvidence);
        if(count($quantities)<1||count($quantities)>100)
            throw new InvalidArgumentException('At least one actually returned item required.');
        $key='private/hall-external-out-return/'.$companyId.'/'.$loanId.'/'.$receiptId;
        $disk=Storage::disk('local');
        return DB::transaction(function()use($witness,$companyId,$hallId,$loanId,$receiptId,
            $quantities,$size,$hash,$bytes,$key,$disk):string {
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$loanId)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_out'||$loan->lending_hall_id!==$hallId)
                throw new ConflictHttpException('Outside return must bind the original lending hall.');
            $this->hall($witness,$companyId,$hallId,false);
            if(DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$companyId)
                ->where('loan_id',$loanId)->exists())
                throw new ConflictHttpException('Physical return cannot change an immutable final NCL certificate.');
            $dispatch=DB::table('hall_inventory_external_out_dispatches')
                ->where('company_id',$companyId)->where('loan_id',$loanId)
                ->where('hall_id',$hallId)->lockForUpdate()->first();
            if(!$dispatch)throw new ConflictHttpException('No independently approved outside dispatch exists.');
            if((int)$loan->created_by===(int)$witness->getKey()||
                (int)$dispatch->approved_by===(int)$witness->getKey())
                throw new AuthorizationException('Different current staff must receive physical partner return.');
            $original=DB::table('hall_inventory_external_out_dispatch_lines')
                ->where('company_id',$companyId)->where('dispatch_id',$dispatch->id)
                ->where('hall_id',$hallId)->orderBy('item_id')->lockForUpdate()
                ->get(['item_id','quantity'])->keyBy('item_id');
            if($original->isEmpty())throw new ConflictHttpException('Original owned partner dispatch is incomplete.');
            $normalized=[];
            foreach($quantities as $itemId=>$qty){
                if(!is_string($itemId)||!Str::isUuid($itemId)||!$original->has($itemId)||
                   !is_int($qty)||$qty<1||$qty>100000000)
                    throw new InvalidArgumentException('Positive return quantity must match an original loan item.');
                $normalized[$itemId]=$qty;
            }
            ksort($normalized,SORT_STRING);
            $old=DB::table('hall_inventory_external_out_return_receipts')
                ->where('company_id',$companyId)->where('id',$receiptId)->first();
            if($old){
                $this->verify($old);
                $prior=DB::table('hall_inventory_external_out_return_lines')
                    ->where('company_id',$companyId)->where('receipt_id',$receiptId)
                    ->pluck('quantity','item_id')->map(fn($x)=>(int)$x)->all();
                ksort($prior,SORT_STRING);
                if($old->loan_id!==$loanId||$old->hall_id!==$hallId||
                   (int)$old->received_by!==(int)$witness->getKey()||
                   (int)$old->evidence_size!==$size||
                   !hash_equals((string)$old->evidence_sha256,$hash)||$prior!==$normalized)
                    throw new ConflictHttpException('Immutable physical return receipt replay differs.');
                return $receiptId;
            }
            $sent=DB::table('hall_inventory_external_out_return_lines as l')
                ->join('hall_inventory_external_out_return_receipts as b',function($j):void {
                    $j->on('b.company_id','=','l.company_id')->on('b.id','=','l.receipt_id');
                })
                ->where('b.company_id',$companyId)->where('b.loan_id',$loanId)
                ->select('l.item_id')->selectRaw('SUM(l.quantity) AS qty')
                ->groupBy('l.item_id')->get()->keyBy('item_id');
            foreach($normalized as $itemId=>$qty) {
                if($qty+(int)($sent->get($itemId)?->qty??0)>(int)$original[$itemId]->quantity)
                    throw new ConflictHttpException('Outside partner physical returns exceed this loan originals.');
            }
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Outside partner return evidence storage failed.');
            try {
                DB::table('hall_inventory_external_out_return_receipts')->insert([
                    'id'=>$receiptId,'company_id'=>$companyId,'loan_id'=>$loanId,
                    'hall_id'=>$hallId,'dispatch_id'=>$dispatch->id,
                    'evidence_sha256'=>$hash,'evidence_size'=>$size,
                    'evidence_storage_path'=>$key,'received_by'=>$witness->getKey()
                ]);
                foreach($normalized as $itemId=>$qty){
                    DB::table('hall_inventory_external_out_return_lines')->insert([
                        'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                        'receipt_id'=>$receiptId,'loan_id'=>$loanId,'hall_id'=>$hallId,
                        'dispatch_id'=>$dispatch->id,'item_id'=>$itemId,'quantity'=>$qty
                    ]);
                }
                DB::table('audit_events')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'actor_id'=>$witness->getKey(),
                    'action'=>'hall_inventory.external_out_partner_return_received',
                    'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$loanId,
                    'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
                    'after_values'=>json_encode(['receiptId'=>$receiptId,
                        'physicalCountByItem'=>$normalized,'postedToOwnedBook'=>false,
                        'financialPosting'=>false],JSON_THROW_ON_ERROR),
                    'context'=>json_encode(['hallId'=>$hallId,'evidenceSha256'=>$hash],JSON_THROW_ON_ERROR)
                ]);
            }catch(\Throwable $e){$disk->delete($key);throw $e;}
            return $receiptId;
        },3);
    }

    /** @param array<string,array{good:int,damaged:int,unresolved:int}> $decisions */
    public function review(User $owner,string $companyId,string $hallId,string $receiptId,
        string $reviewId,array $decisions):string {
        $this->gate();$this->ids($companyId,$hallId,$receiptId,$reviewId);
        return DB::transaction(function()use($owner,$companyId,$hallId,$receiptId,$reviewId,
            $decisions):string {
            $receipt=DB::table('hall_inventory_external_out_return_receipts')
                ->where('company_id',$companyId)->where('id',$receiptId)
                ->where('hall_id',$hallId)->lockForUpdate()->first();
            if(!$receipt)throw new ConflictHttpException('Exact physical partner return receipt missing.');
            $loan=DB::table('hall_inventory_loan_drafts')
                ->where('company_id',$companyId)->where('id',$receipt->loan_id)
                ->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_out'||$loan->lending_hall_id!==$hallId)
                throw new ConflictHttpException('Outside return review cannot change original loan hall.');
            $this->hall($owner,$companyId,$hallId,true);
            if(DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$companyId)
                ->where('loan_id',$receipt->loan_id)->exists())
                throw new ConflictHttpException('Physical return cannot change an immutable final NCL certificate.');
            if((int)$receipt->received_by===(int)$owner->getKey()||
               (int)$loan->created_by===(int)$owner->getKey())
                throw new AuthorizationException('Different owner must independently assess returned partner items.');
            $this->verify($receipt);
            $lines=DB::table('hall_inventory_external_out_return_lines')
                ->where('company_id',$companyId)->where('receipt_id',$receiptId)
                ->orderBy('item_id')->lockForUpdate()->get()->keyBy('item_id');
            if($lines->isEmpty()||$lines->count()!==count($decisions))
                throw new ConflictHttpException('Review all actually returned items separately.');
            $normalized=[];
            foreach($lines as $itemId=>$line) {
                $d=$decisions[$itemId]??null;
                if(!is_array($d)||array_is_list($d)||count($d)!==3||
                   array_diff(array_keys($d),['good','damaged','unresolved'])||
                   !is_int($d['good']??null)||!is_int($d['damaged']??null)||
                   !is_int($d['unresolved']??null)||
                   $d['good']<0||$d['damaged']<0||$d['unresolved']<0||
                   $d['good']+$d['damaged']+$d['unresolved']!==(int)$line->quantity)
                    throw new InvalidArgumentException('Independent condition count must equal exact physical return amount.');
                $normalized[$itemId]=['good'=>$d['good'],'damaged'=>$d['damaged'],
                    'unresolved'=>$d['unresolved']];
            }
            $old=DB::table('hall_inventory_external_out_return_reviews')
                ->where('company_id',$companyId)->where('receipt_id',$receiptId)->first();
            if($old) {
                if((string)$old->id!==$reviewId||
                   (int)$old->reviewed_by!==(int)$owner->getKey())
                    throw new ConflictHttpException('Outside physical condition reviewed by another decision.');
                $rows=DB::table('hall_inventory_external_out_return_review_lines')
                    ->where('company_id',$companyId)->where('review_id',$reviewId)->get()->keyBy('item_id');
                if($rows->count()!==count($normalized))
                    throw new ConflictHttpException('Historical physical condition decision incomplete.');
                foreach($normalized as $itemId=>$q)
                    if((int)$rows[$itemId]->good_quantity!==$q['good']||
                        (int)$rows[$itemId]->damaged_quantity!==$q['damaged']||
                        (int)$rows[$itemId]->unresolved_quantity!==$q['unresolved'])
                        throw new ConflictHttpException('Immutable condition assessment replay differs.');
                return $reviewId;
            }
            $positions=[];
            foreach($normalized as $itemId=>$q){
                $position=DB::table('hall_inventory_positions')
                    ->where('company_id',$companyId)->where('hall_id',$hallId)
                    ->where('item_id',$itemId)->lockForUpdate()->first();
                if(!$position||(int)$position->owned_on_loan<$q['good']+$q['damaged'])
                    throw new ConflictHttpException('Outside partner return exceeds owned-on-loan custody.');
                $positions[$itemId]=$position;
            }
            DB::table('hall_inventory_external_out_return_reviews')->insert([
                'id'=>$reviewId,'company_id'=>$companyId,
                'receipt_id'=>$receiptId,'loan_id'=>$receipt->loan_id,
                'hall_id'=>$hallId,'dispatch_id'=>$receipt->dispatch_id,
                'reviewed_by'=>$owner->getKey()
            ]);
            foreach($normalized as $itemId=>$q){
                $position=$positions[$itemId];
                [$position,$goodMovement]=$this->post($owner,$companyId,$hallId,$itemId,
                    $position,'return_owned_good',$q['good'],$receiptId,$receipt->evidence_sha256);
                [$position,$damagedMovement]=$this->post($owner,$companyId,$hallId,$itemId,
                    $position,'return_owned_damaged',$q['damaged'],$receiptId,$receipt->evidence_sha256);
                DB::table('hall_inventory_external_out_return_review_lines')->insert([
                    'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                    'review_id'=>$reviewId,'receipt_id'=>$receiptId,
                    'hall_id'=>$hallId,'item_id'=>$itemId,
                    'good_quantity'=>$q['good'],'damaged_quantity'=>$q['damaged'],
                    'unresolved_quantity'=>$q['unresolved'],
                    'good_movement_id'=>$goodMovement,'damaged_movement_id'=>$damagedMovement
                ]);
            }
            DB::table('audit_events')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$companyId,
                'actor_id'=>$owner->getKey(),
                'action'=>'hall_inventory.external_out_partner_return_condition_accepted',
                'entity_type'=>'hall_inventory_loan_draft','entity_id'=>$receipt->loan_id,
                'before_values'=>json_encode(['state'=>'WITNESSED_PHYSICAL_RETURN_ONLY'],JSON_THROW_ON_ERROR),
                'after_values'=>json_encode(['receiptId'=>$receiptId,'reviewId'=>$reviewId,
                    'conditions'=>$normalized,'financialPosting'=>false,
                    'loanClosed'=>false],JSON_THROW_ON_ERROR),
                'context'=>json_encode(['hallId'=>$hallId,
                    'signedReturnSha256'=>$receipt->evidence_sha256],JSON_THROW_ON_ERROR)
            ]);
            return $reviewId;
        },3);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(User $actor,string $companyId,string $hallId):array {
        $this->gate();$this->ids($companyId,$hallId);
        $hall=Hall::query()->where('company_id',$companyId)
            ->where('id',$hallId)->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Outside return history is scoped to current hall.');
        $list=DB::table('hall_inventory_external_out_return_receipts as r')
            ->leftJoin('hall_inventory_external_out_return_reviews as a',function($j):void {
                $j->on('a.company_id','=','r.company_id')->on('a.receipt_id','=','r.id');
            })
            ->where('r.company_id',$companyId)->where('r.hall_id',$hallId)
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->limit(20)
            ->get(['r.id','r.loan_id','r.received_by','r.created_at','a.id as review_id','a.reviewed_by']);
        $lines=DB::table('hall_inventory_external_out_return_lines')
            ->where('company_id',$companyId)->whereIn('receipt_id',$list->pluck('id')->all())
            ->get(['receipt_id','item_id','quantity'])->groupBy('receipt_id');
        $reviews=DB::table('hall_inventory_external_out_return_review_lines')
            ->where('company_id',$companyId)
            ->whereIn('receipt_id',$list->pluck('id')->all())
            ->get(['receipt_id','item_id','good_quantity','damaged_quantity','unresolved_quantity'])
            ->groupBy('receipt_id');
        return $list->map(fn($x)=>[
            'receiptId'=>(string)$x->id,'loanId'=>(string)$x->loan_id,
            'receivedBy'=>(int)$x->received_by,
            'reviewedBy'=>$x->reviewed_by===null?null:(int)$x->reviewed_by,
            'reviewed'=>$x->review_id!==null,
            'lines'=>($lines[$x->id]??collect())->map(fn($v)=>[
                'itemId'=>(string)$v->item_id,'quantity'=>(int)$v->quantity
            ])->all(),
            'conditions'=>($reviews[$x->id]??collect())->map(fn($v)=>[
                'itemId'=>(string)$v->item_id,'good'=>(int)$v->good_quantity,
                'damaged'=>(int)$v->damaged_quantity,
                'unresolved'=>(int)$v->unresolved_quantity
            ])->all(),
        ])->all();
    }
}