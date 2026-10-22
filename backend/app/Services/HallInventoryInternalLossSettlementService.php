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

/**
 * Private INTERNAL settlement evidence and independent verification.
 * Records the claimed cash/bank transfer only. It never posts a journal,
 * mutates cash, retires stock, waives the obligation or closes the loan.
 */
final class HallInventoryInternalLossSettlementService
{
    public function __construct(private readonly TenantAccess $access) {}
    private function gate():void {
        foreach(['loan_drafts_enabled','loan_returns_enabled','loan_return_acceptance_enabled',
            'internal_discrepancy_enabled','internal_physical_finding_enabled',
            'internal_loss_obligation_enabled','internal_loss_settlement_enabled'] as $key)
            if(!config('inventory_controls.'.$key))
                throw new ConflictHttpException('Internal settlement evidence is not released.');
    }
    private function proofBytes(UploadedFile $file):array {
        $bytes=(string)file_get_contents($file->getRealPath());
        $size=strlen($bytes);
        if($size<1||$size>5242880)
            throw new InvalidArgumentException('Settlement proof size is outside the approved bound.');
        $mime=$file->getMimeType();
        if(!in_array($mime,['application/pdf','image/jpeg','image/png'],true)||
            !str_starts_with($bytes,$mime==='application/pdf'?'%PDF-':($mime==='image/png'?"\x89PNG":"\xFF\xD8\xFF")))
            throw new InvalidArgumentException('Settlement proof must be a real PDF, JPEG or PNG.');
        return [$bytes,$size,hash('sha256',$bytes),
            $mime==='application/pdf'?'pdf':($mime==='image/png'?'png':'jpg')];
    }
    public function record(User $actor,string $companyId,string $sourceHallId,string $obligationId,
        string $settlementId,int $amountMinor,string $currency,string $method,string $reference,
        string $paidOn,UploadedFile $proof):string {
        $this->gate();
        foreach([$companyId,$sourceHallId,$obligationId,$settlementId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact internal settlement UUIDs required.');
        if($amountMinor<1||$amountMinor>100000000000||
            !preg_match('/\A[A-Z]{3}\z/D',$currency)||
            !in_array($method,['cash','bank'],true)||
            mb_strlen(trim($reference))<3||mb_strlen($reference)>100||
            !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$paidOn))
            throw new InvalidArgumentException('Invalid internal settlement metadata.');
        [$bytes,$size,$sha,$ext]=$this->proofBytes($proof);
        $reference=trim($reference);
        return DB::transaction(function()use($actor,$companyId,$sourceHallId,$obligationId,
            $settlementId,$amountMinor,$currency,$method,$reference,$paidOn,$bytes,$size,$sha,$ext):string {
            $obligation=DB::table('hall_inventory_internal_loss_obligations')
                ->where('company_id',$companyId)->where('source_hall_id',$sourceHallId)
                ->where('id',$obligationId)->lockForUpdate()->first();
            if(!$obligation)throw new AuthorizationException('Obligation is outside this source hall.');
            $decision=DB::table('hall_inventory_internal_loss_obligation_decisions')
                ->where('company_id',$companyId)->where('obligation_id',$obligationId)->first();
            if(!$decision||$decision->decision!=='approved')
                throw new ConflictHttpException('Settlement evidence requires an approved internal obligation.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current source-hall owner/admin required.');
            if($currency!==(string)$obligation->currency)
                throw new ConflictHttpException('Settlement currency must match the obligation.');
            if($amountMinor>(int)$obligation->amount_minor)
                throw new ConflictHttpException('Settlement evidence exceeds the approved obligation.');
            $fingerprint=hash('sha256',json_encode([$companyId,$sourceHallId,$obligationId,
                $amountMinor,$currency,$method,$reference,$paidOn,$sha,(int)$actor->getKey()],
                JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_internal_loss_settlements')
                ->where('company_id',$companyId)->where('obligation_id',$obligationId)->first();
            if($old) {
                if((string)$old->id!==$settlementId||
                    (int)$old->recorded_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->proof_sha256,$sha)||
                    (int)$old->amount_minor!==$amountMinor||
                    (string)$old->currency!==$currency||
                    (string)$old->method!==$method||
                    (string)$old->paid_on!==$paidOn||
                    (string)$old->reference!==$reference)
                    throw new ConflictHttpException('Internal settlement already has different immutable evidence.');
                return $settlementId;
            }
            $path='private/hall-internal-loss-settlements/'.$companyId.'/'.$obligationId.'/'.
                $settlementId.'.'.$ext;
            $disk=Storage::disk('local');
            if($disk->exists($path))throw new ConflictHttpException('Settlement proof key already occupied.');
            if(!$disk->put($path,$bytes))throw new ConflictHttpException('Settlement proof could not be stored.');
            try {
                DB::table('hall_inventory_internal_loss_settlements')->insert([
                    'id'=>$settlementId,'company_id'=>$companyId,'obligation_id'=>$obligationId,
                    'source_hall_id'=>$sourceHallId,'borrowing_hall_id'=>$obligation->borrowing_hall_id,
                    'amount_minor'=>$amountMinor,'currency'=>$currency,'method'=>$method,
                    'reference'=>$reference,'paid_on'=>$paidOn,'proof_path'=>$path,
                    'proof_sha256'=>$sha,'proof_size'=>$size,'recorded_by'=>$actor->getKey()
                ]);
                $this->audit($actor,$companyId,'recorded',$settlementId,(string)$obligation->loan_id,
                    ['obligationId'=>$obligationId,'amountMinor'=>$amountMinor,'currency'=>$currency,
                     'method'=>$method,'proofSha256'=>$sha,'journalPosted'=>false,
                     'cashMutatedByThisLayer'=>false,'stockRetired'=>false,'loanClosed'=>false]);
            }catch(\Throwable $e){$disk->delete($path);throw $e;}
            return $settlementId;
        },3);
    }
    public function review(User $actor,string $companyId,string $sourceHallId,string $settlementId,
        string $reviewId,string $decision,string $reason):string {
        $this->gate();
        foreach([$companyId,$sourceHallId,$settlementId,$reviewId] as $id)
            if(!Str::isUuid($id))throw new InvalidArgumentException('Exact settlement review UUIDs required.');
        if(!in_array($decision,['verified','rejected'],true)||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Invalid independent settlement review.');
        $reason=trim($reason);
        return DB::transaction(function()use($actor,$companyId,$sourceHallId,$settlementId,
            $reviewId,$decision,$reason):string {
            $settlement=DB::table('hall_inventory_internal_loss_settlements')
                ->where('company_id',$companyId)->where('source_hall_id',$sourceHallId)
                ->where('id',$settlementId)->lockForUpdate()->first();
            if(!$settlement)throw new AuthorizationException('Settlement is outside this source hall.');
            $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
                ->where('is_active',true)->lockForUpdate()->first();
            if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
                !$this->access->canConfigureHall($actor,$hall))
                throw new AuthorizationException('Current source-hall owner/admin required.');
            if((int)$settlement->recorded_by===(int)$actor->getKey())
                throw new AuthorizationException('Settlement recorder cannot verify their own evidence.');
            $obligation=DB::table('hall_inventory_internal_loss_obligations')
                ->where('company_id',$companyId)->where('id',$settlement->obligation_id)->first();
            if($obligation&&(int)$obligation->proposed_by===(int)$actor->getKey())
                throw new AuthorizationException('Obligation proposer cannot verify the settlement.');
            $fingerprint=hash('sha256',json_encode([$companyId,$sourceHallId,$settlementId,
                (int)$actor->getKey(),$decision,$reason],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_internal_loss_settlement_reviews')
                ->where('company_id',$companyId)->where('settlement_id',$settlementId)->first();
            if($old) {
                if((string)$old->id!==$reviewId||
                    (int)$old->reviewed_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Internal settlement already has an immutable review.');
                return $reviewId;
            }
            DB::table('hall_inventory_internal_loss_settlement_reviews')->insert([
                'id'=>$reviewId,'company_id'=>$companyId,'settlement_id'=>$settlementId,
                'decision'=>$decision,'reason'=>$reason,'fingerprint'=>$fingerprint,
                'reviewed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'reviewed',$reviewId,
                $obligation ? (string)$obligation->loan_id : '',
                ['settlementId'=>$settlementId,'decision'=>$decision,'journalPosted'=>false,
                 'cashMutatedByThisLayer'=>false,'stockRetired'=>false,'loanClosed'=>false]);
            return $reviewId;
        },3);
    }
    public function recent(User $actor,string $companyId,string $sourceHallId):array {
        $this->gate();
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$sourceHallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canManageHallInventory($actor,$hall))
            throw new AuthorizationException('Internal settlement history is source-hall restricted.');
        return DB::table('hall_inventory_internal_loss_settlements as s')
            ->leftJoin('hall_inventory_internal_loss_settlement_reviews as r',function($j):void {
                $j->on('r.company_id','=','s.company_id')->on('r.settlement_id','=','s.id');
            })->where('s.company_id',$companyId)->where('s.source_hall_id',$sourceHallId)
            ->orderByDesc('s.created_at')->orderByDesc('s.id')->limit(100)
            ->get(['s.id','s.obligation_id','s.amount_minor','s.currency','s.method',
                's.reference','s.paid_on','s.proof_sha256','s.recorded_by','r.decision',
                'r.reviewed_by'])->map(static fn($x):array=>[
                    'id'=>(string)$x->id,'obligationId'=>(string)$x->obligation_id,
                    'amountMinor'=>(int)$x->amount_minor,'currency'=>(string)$x->currency,
                    'method'=>(string)$x->method,'reference'=>(string)$x->reference,
                    'paidOn'=>(string)$x->paid_on,'proofSha256'=>(string)$x->proof_sha256,
                    'recordedBy'=>(int)$x->recorded_by,'decision'=>$x->decision,
                    'reviewedBy'=>$x->reviewed_by,'journalPosted'=>false,
                    'stockRetired'=>false,'loanClosed'=>false])->all();
    }
    private function audit(User $actor,string $companyId,string $action,string $id,
        string $loanId,array $data):void {
        DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),
            'action'=>'hall_inventory.internal_loss_settlement_'.$action,
            'entity_type'=>'hall_inventory_internal_loss_settlement','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId],JSON_THROW_ON_ERROR)]);
    }
}
