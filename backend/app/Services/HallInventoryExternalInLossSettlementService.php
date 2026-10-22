<?php
namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Private evidence of a partner payment against an approved provisional
 * EXTERNAL_IN loss obligation, then independent verification. This does NOT
 * post a journal, mutate cash/bank balances, recognise a settlement, waive a
 * debt or close the loan. Ledger posting is a separate gated feature.
 */
final class HallInventoryExternalInLossSettlementService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate():void {
        foreach(['loan_drafts_enabled','external_borrowed_intake_enabled',
            'external_borrowed_return_enabled','external_borrowed_discrepancy_enabled',
            'external_in_loss_obligation_enabled','external_in_loss_settlement_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new ConflictHttpException('External-in partner payment evidence is not released.');
    }
    private function evidence(UploadedFile $file):array {
        $path=$file->getRealPath(); $size=$file->getSize();
        if(!$file->isValid()||!$path||!is_file($path)||!is_int($size)||$size<1||$size>5242880||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/jpeg','image/png'],true))
            throw new InvalidArgumentException('Partner payment evidence must be PDF/photo, 5 MB maximum.');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Partner payment evidence could not be read.');
        return [$bytes,$size,hash('sha256',$bytes)];
    }
    private function verified(object $row):void {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->evidence_storage_path))
            throw new ConflictHttpException('Partner payment evidence is missing.');
        $bytes=$disk->get((string)$row->evidence_storage_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
            !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Partner payment evidence failed integrity verification.');
    }
    public function record(User $actor,string $companyId,string $hallId,string $obligationId,
        string $settlementId,int $amountMinor,string $currency,string $method,
        string $paymentReference,string $paidOn,UploadedFile $file):string {
        $this->gate();
        foreach([$companyId,$hallId,$obligationId,$settlementId] as $id)
            if(!Str::isUuid($id))
                throw new InvalidArgumentException('Exact external-in settlement UUIDs are required.');
        $paymentReference=trim($paymentReference);
        if(!preg_match('/\A[A-Z]{3}\z/D',$currency)||
            !in_array($method,['cash','bank'],true)||
            mb_strlen($paymentReference)<1||mb_strlen($paymentReference)>120||
            !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$paidOn)||
            $amountMinor<1||$amountMinor>100000000000)
            throw new InvalidArgumentException('Invalid external-in partner payment evidence.');
        try {
            $paidAt=Carbon::createFromFormat('Y-m-d',$paidOn,'UTC')->startOfDay();
        }catch(\Throwable) {
            throw new InvalidArgumentException('Invalid partner payment date.');
        }
        [$bytes,$size,$sha]=$this->evidence($file);
        $disk=Storage::disk('local');
        $key='private/hall-external-in-settlement/'.$companyId.'/'.$obligationId.'/'.$settlementId;
        return DB::transaction(function()use($actor,$companyId,$hallId,$obligationId,
            $settlementId,$amountMinor,$currency,$method,$paymentReference,$paidAt,
            $bytes,$size,$sha,$disk,$key):string {
            $ref=DB::table('hall_inventory_external_in_loss_obligations')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$obligationId)->first(['loan_id','amount_minor','currency']);
            if(!$ref)throw new AuthorizationException('External-in loss obligation is outside this hall.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$ref->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||
                (string)$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Settlement must belong to the original borrowing-hall loan.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current borrowing-hall owner/admin required.');
            $obligation=DB::table('hall_inventory_external_in_loss_obligations')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$obligationId)->lockForUpdate()->first();
            if(!$obligation)throw new ConflictHttpException('External-in loss obligation was not found.');
            $decision=DB::table('hall_inventory_external_in_loss_obligation_decisions')
                ->where('company_id',$companyId)->where('obligation_id',$obligationId)
                ->where('decision','approved')->first();
            if(!$decision||$decision->decision!=='approved')
                throw new ConflictHttpException('Only an independently approved obligation may receive payment evidence.');
            if($currency!==(string)$obligation->currency)
                throw new ConflictHttpException('Payment currency must match the approved obligation currency.');
            if($amountMinor>(int)$obligation->amount_minor)
                throw new ConflictHttpException('Payment evidence exceeds the independently approved amount.');
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$obligationId,
                $settlementId,$amountMinor,$currency,$method,$paymentReference,
                $paidAt->toDateString(),$sha,(int)$actor->getKey()],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_external_in_loss_settlements')
                ->where('company_id',$companyId)->where('obligation_id',$obligationId)->first();
            if($old) {
                if((string)$old->id!==$settlementId||
                    (int)$old->recorded_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('External-in settlement already exists with different immutable evidence.');
                $this->verified($old);
                return $settlementId;
            }
            if($disk->exists($key))
                throw new ConflictHttpException('Partner payment evidence storage key already occupied.');
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Partner payment evidence could not be stored.');
            try {
                DB::table('hall_inventory_external_in_loss_settlements')->insert([
                    'id'=>$settlementId,'company_id'=>$companyId,'hall_id'=>$hallId,
                    'loan_id'=>$loan->id,'obligation_id'=>$obligationId,
                    'decision_id'=>$decision->id,'amount_minor'=>$amountMinor,
                    'currency'=>$currency,'method'=>$method,'payment_reference'=>$paymentReference,
                    'paid_at'=>$paidAt,'evidence_sha256'=>$sha,'evidence_size'=>$size,
                    'evidence_storage_path'=>$key,'fingerprint'=>$fingerprint,
                    'recorded_by'=>$actor->getKey()
                ]);
                $this->audit($actor,$companyId,'recorded',$settlementId,(string)$loan->id,
                    ['obligationId'=>$obligationId,'amountMinor'=>$amountMinor,
                     'currency'=>$currency,'method'=>$method,'journalPosted'=>false,
                     'cashMutated'=>false,'loanClosed'=>false]);
            }catch(\Throwable $e){$disk->delete($key);throw $e;}
            return $settlementId;
        },3);
    }
    public function review(User $actor,string $companyId,string $hallId,string $settlementId,
        string $reviewId,string $decision,string $reason):string {
        $this->gate();
        foreach([$companyId,$hallId,$settlementId,$reviewId] as $id)
            if(!Str::isUuid($id))
                throw new InvalidArgumentException('Exact external-in settlement review UUIDs are required.');
        if(!in_array($decision,['verified','rejected'],true)||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independent partner payment review and reason required.');
        $reason=trim($reason);
        return DB::transaction(function()use($actor,$companyId,$hallId,$settlementId,
            $reviewId,$decision,$reason):string {
            $ref=DB::table('hall_inventory_external_in_loss_settlements')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$settlementId)->first(['loan_id','recorded_by']);
            if(!$ref)throw new AuthorizationException('Partner payment evidence is outside this hall.');
            $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$companyId)
                ->where('id',$ref->loan_id)->lockForUpdate()->first();
            if(!$loan||$loan->direction!=='external_in'||
                (string)$loan->borrowing_hall_id!==$hallId)
                throw new ConflictHttpException('Payment evidence no longer belongs to the original borrowing-hall loan.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current borrowing-hall owner/admin required.');
            $settlement=DB::table('hall_inventory_external_in_loss_settlements')
                ->where('company_id',$companyId)->where('hall_id',$hallId)
                ->where('id',$settlementId)->lockForUpdate()->first();
            if(!$settlement)throw new ConflictHttpException('Partner payment evidence was not found.');
            if((int)$settlement->recorded_by===(int)$actor->getKey())
                throw new AuthorizationException('Payment recorder cannot independently verify their own evidence.');
            $this->verified($settlement);
            $fingerprint=hash('sha256',json_encode([$companyId,$hallId,$settlementId,
                (int)$actor->getKey(),$decision,$reason],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_external_in_loss_settlement_reviews')
                ->where('company_id',$companyId)->where('settlement_id',$settlementId)->first();
            if($old) {
                if((string)$old->id!==$reviewId||
                    (int)$old->reviewed_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Partner payment evidence already has an immutable review.');
                return $reviewId;
            }
            DB::table('hall_inventory_external_in_loss_settlement_reviews')->insert([
                'id'=>$reviewId,'company_id'=>$companyId,'hall_id'=>$hallId,
                'settlement_id'=>$settlementId,'decision'=>$decision,'reason'=>$reason,
                'fingerprint'=>$fingerprint,'reviewed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'reviewed',$reviewId,(string)$loan->id,
                ['settlementId'=>$settlementId,'decision'=>$decision,
                 'journalPosted'=>false,'cashMutated'=>false,'loanClosed'=>false]);
            return $reviewId;
        },3);
    }
    /** Read-only payment evidence history for current hall inventory staff. */
    public function recent(User $actor,string $companyId,string $hallId):array {
        $this->gate();
        foreach([$companyId,$hallId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Invalid settlement scope.');
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Partner payment evidence history is hall-restricted.');
        return DB::table('hall_inventory_external_in_loss_settlements as s')
            ->leftJoin('hall_inventory_external_in_loss_settlement_reviews as r',function($j):void {
                $j->on('r.company_id','=','s.company_id')->on('r.settlement_id','=','s.id');
            })->where('s.company_id',$companyId)->where('s.hall_id',$hallId)
            ->orderByDesc('s.created_at')->orderByDesc('s.id')->limit(100)
            ->get(['s.id','s.obligation_id','s.amount_minor','s.currency','s.method',
                's.payment_reference','s.paid_at','s.recorded_by','r.decision','r.reviewed_by'])
            ->map(static fn($x):array=>[
                'id'=>(string)$x->id,'obligationId'=>(string)$x->obligation_id,
                'amountMinor'=>(int)$x->amount_minor,'currency'=>(string)$x->currency,
                'method'=>(string)$x->method,'paymentReference'=>(string)$x->payment_reference,
                'paidAt'=>(string)$x->paid_at,'recordedBy'=>(int)$x->recorded_by,
                'decision'=>$x->decision,'reviewedBy'=>$x->reviewed_by,
                'journalPosted'=>false,'cashMutated'=>false,'loanClosed'=>false,
            ])->all();
    }
    private function audit(User $actor,string $companyId,string $action,string $id,
        string $loanId,array $data):void {
        DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.external_in_loss_settlement_'.$action,
            'entity_type'=>'hall_inventory_external_in_loss_settlement','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId],JSON_THROW_ON_ERROR)]);
    }
}
