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
 * Two original-hall signed sources plus independent lender decision.
 * "confirmed_outstanding" is a physical DISCREPANCY finding, not a
 * stock return, physical retirement, debt, waiver, cash or final certificate.
 */
final class HallInventoryInternalPhysicalFindingService {
    public function __construct(private readonly TenantAccess $access) {}

    private function gate(): void {
        foreach (['loan_drafts_enabled','loan_dispatch_enabled','loan_receipt_enabled',
            'loan_returns_enabled','loan_return_acceptance_enabled','internal_discrepancy_enabled',
            'loan_incidents_enabled','loan_incident_reviews_enabled','internal_physical_finding_enabled'] as $key)
            if (!config('inventory_controls.'.$key))
                throw new ConflictHttpException('Original-loan physical discrepancy findings are not released.');
    }
    private function ids(string ...$ids): void {
        foreach($ids as $id) if (!Str::isUuid($id))
            throw new InvalidArgumentException('Original loan UUID required.');
    }
    private function scope(User $actor,string $company,string $hall,bool $source): void {
        $h=Hall::query()->where('company_id',$company)->where('id',$hall)
            ->where('is_active',true)->first();
        if (!$h || !Auth::check() || (int)Auth::id()!==(int)$actor->getKey()
            || !($source?$this->access->canConfigureHall($actor,$h):
                $this->access->canManageHallInventory($actor,$h)))
            throw new AuthorizationException('Current original-hall inventory permission required.');
    }
    private function checkedProof(string $path,string $sha,int $size): string {
        // Evidence is never exposed by a user-supplied storage path.
        if (!str_starts_with($path,'private/hall-internal-') ||
            !Storage::disk('local')->exists($path))
            throw new ConflictHttpException('Original private evidence is missing.');
        $bytes=Storage::disk('local')->get($path);
        if(!is_string($bytes)||strlen($bytes)!==$size||!hash_equals($sha,hash('sha256',$bytes)))
            throw new ConflictHttpException('Original signed private evidence failed integrity check.');
        return $bytes;
    }
    private function openLoan(string $company,string $loanId,string $hall,bool $source): object {
        $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$company)
            ->where('id',$loanId)->lockForUpdate()->first();
        if (!$loan || $loan->direction!=='internal' ||
            (string)($source?$loan->lending_hall_id:$loan->borrowing_hall_id)!==$hall)
            throw new AuthorizationException('Original INTERNAL loan does not belong to this hall.');
        if(DB::table('hall_inventory_internal_loan_closures')->where('company_id',$company)
            ->where('loan_id',$loanId)->exists() ||
           DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$company)
            ->where('loan_id',$loanId)->exists())
            throw new ConflictHttpException('Final loan closure prevents new discrepancy findings.');
        return $loan;
    }
    private function testimony(string $company,string $loanId,string $statementId,string $incidentId): array {
        $signed=DB::table('hall_inventory_internal_discrepancy_statements as s')
            ->join('hall_inventory_internal_discrepancy_decisions as d',function($j):void {
                $j->on('d.company_id','=','s.company_id')->on('d.statement_id','=','s.id');
            })->where('s.company_id',$company)->where('s.loan_id',$loanId)
            ->where('s.id',$statementId)->where('d.decision','corroborated')
            ->first(['s.id','s.source_item_id','s.borrowing_hall_id','s.kind','s.quantity',
                's.submitted_by','s.evidence_storage_path','s.evidence_sha256','s.evidence_size','d.reviewed_by']);
        $incident=DB::table('hall_inventory_loan_incident_reports as i')
            ->join('hall_inventory_loan_incident_decisions as d',function($j):void {
                $j->on('d.company_id','=','i.company_id')->on('d.report_id','=','i.id');
            })->where('i.company_id',$company)->where('i.loan_id',$loanId)
            ->where('i.id',$incidentId)->where('d.decision','substantiated')
            ->first(['i.id','i.source_item_id','i.source_hall_id','i.kind','i.quantity',
                'i.reported_by','d.reviewed_by']);
        if (!$signed || !$incident || $signed->kind!==$incident->kind ||
            (string)$signed->source_item_id!==(string)$incident->source_item_id ||
            (int)$signed->quantity!==(int)$incident->quantity)
            throw new ConflictHttpException('Independent original borrower/lender evidence does not match exact item, kind and quantity.');
        $this->checkedProof((string)$signed->evidence_storage_path,(string)$signed->evidence_sha256,
            (int)$signed->evidence_size);
        return [$signed,$incident];
    }
    private function physical(User $actor,string $company,string $hall,string $loanId,string $itemId): array {
        $rows=app(HallInventoryInternalLoanReturnReview::class)
            ->abnormalSourceLines($actor,$company,$hall,$loanId);
        $row=collect($rows)->first(fn(array $r):bool=>$r['itemId']===$itemId);
        if (!$row || $row['integrityWarning'])
            throw new ConflictHttpException('Original loan per-item custody integrity requires review.');
        $outstanding=(int)$row['notReturned']+(int)$row['unresolved'];
        if ($outstanding<1)
            throw new ConflictHttpException('No independently unreconciled physical units remain on this original item.');
        // Already-sent transit is never a missing physical return; borrower
        // catalog positions are aggregate across loans and are not used here.
        return ['outstanding'=>$outstanding, 'inTransit'=>(int)$row['inTransit']];
    }
    public function propose(User $actor,string $company,string $hall,string $loanId,
        string $statementId,string $incidentId,string $findingId,string $reason,UploadedFile $proof): string {
        $this->gate(); $this->ids($company,$hall,$loanId,$statementId,$incidentId,$findingId);
        $reason=trim($reason);
        if (mb_strlen($reason)<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Document a physical original-loan investigation.');
        $path=$proof->getRealPath();$size=$proof->getSize();
        if (!$proof->isValid()||!$path||!is_file($path)||!is_int($size)||$size<1||$size>5242880||
            !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
                ['application/pdf','image/png','image/jpeg'],true))
            throw new InvalidArgumentException('Signed source physical proof PDF/photo required.');
        $bytes=file_get_contents($path);
        if (!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Source proof unreadable.');
        $sha=hash('sha256',$bytes);
        return DB::transaction(function()use($actor,$company,$hall,$loanId,$statementId,$incidentId,
            $findingId,$reason,$size,$sha,$bytes):string {
            $loan=$this->openLoan($company,$loanId,$hall,true);
            $this->scope($actor,$company,$hall,true);
            [$signed,$incident]=$this->testimony($company,$loanId,$statementId,$incidentId);
            if ((string)$signed->borrowing_hall_id!==(string)$loan->borrowing_hall_id ||
                (string)$incident->source_hall_id!==$hall ||
                (int)$actor->getKey()===(int)$signed->submitted_by ||
                (int)$actor->getKey()===(int)$incident->reported_by)
                throw new AuthorizationException('Independent source investigation requires another authorized actor.');
            $physical=$this->physical($actor,$company,$hall,$loanId,(string)$signed->source_item_id);
            $qty=(int)$signed->quantity;
            $fingerprint=hash('sha256',json_encode([$company,$hall,$loanId,$statementId,
                $incidentId,$qty,$reason,$sha,(int)$actor->getKey()],JSON_THROW_ON_ERROR));
            $disk=Storage::disk('local');
            $old=DB::table('hall_inventory_internal_physical_findings')
                ->where('company_id',$company)->where('id',$findingId)->first();
            if ($old) {
                if (!hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Conflicting original source finding replay.');
                $this->checkedProof((string)$old->source_proof_path,
                    (string)$old->source_proof_sha256,(int)$old->source_proof_size);
                return $findingId;
            }
            $allocated=DB::table('hall_inventory_internal_physical_findings as f')
                ->leftJoin('hall_inventory_internal_physical_finding_decisions as d',function($j):void {
                    $j->on('d.company_id','=','f.company_id')->on('d.finding_id','=','f.id');
                })->where('f.company_id',$company)->where('f.loan_id',$loanId)
                ->where('f.source_item_id',$signed->source_item_id)
                ->where(function($q):void {$q->whereNull('d.id')->orWhere('d.decision','confirmed_outstanding');})
                ->sum('f.quantity');
            if ($qty>(int)$physical['outstanding']-(int)$allocated)
                throw new ConflictHttpException('Physical discrepancy already reserved or returned under this original loan.');
            $key='private/hall-internal-finding/'.$company.'/'.$loanId.'/'.$findingId;
            if ($disk->exists($key)) throw new ConflictHttpException('Private proof key already occupied.');
            if (!$disk->put($key,$bytes)) throw new ConflictHttpException('Source physical proof storage failed.');
            try {
                DB::table('hall_inventory_internal_physical_findings')->insert([
                    'id'=>$findingId,'company_id'=>$company,'loan_id'=>$loanId,
                    'source_hall_id'=>$hall,'borrowing_hall_id'=>$loan->borrowing_hall_id,
                    'source_item_id'=>$signed->source_item_id,'borrower_statement_id'=>$statementId,
                    'source_incident_id'=>$incidentId,'kind'=>$signed->kind,'quantity'=>$qty,
                    'outstanding_snapshot'=>$physical['outstanding'],'reason'=>$reason,'fingerprint'=>$fingerprint,
                    'source_proof_path'=>$key,'source_proof_sha256'=>$sha,'source_proof_size'=>$size,
                    'proposed_by'=>$actor->getKey()
                ]);
                $this->audit($actor,$company,'proposed',$findingId,$loanId,
                    ['sourceItemId'=>$signed->source_item_id,'quantity'=>$qty,
                     'sourceProofSha256'=>$sha,'borrowerStatementId'=>$statementId,
                     'sourceIncidentId'=>$incidentId,'stockMoved'=>false,'moneyPosted'=>false]);
            }catch(\Throwable $e){$disk->delete($key);throw $e;}
            return $findingId;
        },3);
    }
    public function decide(User $actor,string $company,string $hall,string $findingId,
        string $decisionId,string $decision,string $reason): string {
        $this->gate();$this->ids($company,$hall,$findingId,$decisionId);
        $reason=trim($reason);
        if (!in_array($decision,['confirmed_outstanding','rejected','requires_more_evidence'],true) ||
            mb_strlen($reason)<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independent physical finding decision and reason required.');
        return DB::transaction(function()use($actor,$company,$hall,$findingId,$decisionId,$decision,$reason):string {
            $ref=DB::table('hall_inventory_internal_physical_findings')
                ->where('company_id',$company)->where('id',$findingId)->first(['loan_id']);
            if (!$ref)throw new AuthorizationException('Original loan finding does not exist in scope.');
            $this->openLoan($company,(string)$ref->loan_id,$hall,true);
            $this->scope($actor,$company,$hall,true);
            $finding=DB::table('hall_inventory_internal_physical_findings')->where('company_id',$company)
                ->where('loan_id',$ref->loan_id)->where('id',$findingId)->lockForUpdate()->first();
            if (!$finding||$finding->source_hall_id!==$hall)
                throw new AuthorizationException('Finding is not from this original lending hall.');
            if ((int)$actor->getKey()===(int)$finding->proposed_by)
                throw new AuthorizationException('Original source investigator cannot independently decide their own evidence.');
            $this->checkedProof((string)$finding->source_proof_path,(string)$finding->source_proof_sha256,
                (int)$finding->source_proof_size);
            [$signed,$incident]=$this->testimony($company,(string)$ref->loan_id,
                (string)$finding->borrower_statement_id,(string)$finding->source_incident_id);
            if ((int)$actor->getKey()===(int)$signed->submitted_by)
                throw new AuthorizationException('Borrower cannot decide source physical findings.');
            $fingerprint=hash('sha256',json_encode([$company,$findingId,(int)$actor->getKey(),
                $decision,$reason],JSON_THROW_ON_ERROR));
            $old=DB::table('hall_inventory_internal_physical_finding_decisions')
                ->where('company_id',$company)->where('finding_id',$findingId)->first();
            if ($old) {
                if ((string)$old->id!==$decisionId||!hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Independent physical finding is already immutable.');
                return $decisionId;
            }
            if ($decision==='confirmed_outstanding') {
                $physical=$this->physical($actor,$company,$hall,(string)$ref->loan_id,(string)$finding->source_item_id);
                if ((int)$physical['outstanding']<(int)$finding->quantity)
                    throw new ConflictHttpException('Original physical custody changed before final evidence review.');
                $reserved=DB::table('hall_inventory_internal_physical_findings as f')
                    ->join('hall_inventory_internal_physical_finding_decisions as d',function($j):void {
                        $j->on('d.company_id','=','f.company_id')->on('d.finding_id','=','f.id');
                    })->where('f.company_id',$company)->where('f.loan_id',$ref->loan_id)
                    ->where('f.source_item_id',$finding->source_item_id)
                    ->where('f.id','!=',$findingId)->where('d.decision','confirmed_outstanding')->sum('f.quantity');
                if ((int)$reserved+(int)$finding->quantity>(int)$physical['outstanding'])
                    throw new ConflictHttpException('Physical discrepancy is overallocated after newer returns.');
            }
            DB::table('hall_inventory_internal_physical_finding_decisions')->insert([
                'id'=>$decisionId,'company_id'=>$company,'finding_id'=>$findingId,
                'decision'=>$decision,'reason'=>$reason,'fingerprint'=>$fingerprint,
                'decided_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$company,'decided',$decisionId,(string)$ref->loan_id,[
                'findingId'=>$findingId,'decision'=>$decision,'stockMoved'=>false,
                'financialSettlement'=>false,'closureAuthorized'=>false]);
            return $decisionId;
        },3);
    }
    public function history(User $actor,string $company,string $hall,string $loanId,bool $source):array {
        $this->gate();$this->ids($company,$hall,$loanId);
        $this->scope($actor,$company,$hall,$source);
        $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$company)
            ->where('id',$loanId)->where('direction','internal')
            ->where($source?'lending_hall_id':'borrowing_hall_id',$hall)->first();
        if (!$loan)throw new AuthorizationException('Original loan finding history denied.');
        return DB::table('hall_inventory_internal_physical_findings as f')
            ->leftJoin('hall_inventory_internal_physical_finding_decisions as d',function($j):void {
                $j->on('d.company_id','=','f.company_id')->on('d.finding_id','=','f.id');
            })->where('f.company_id',$company)->where('f.loan_id',$loanId)
            ->orderBy('f.created_at')->orderBy('f.id')->limit(100)
            ->get(['f.id','f.source_item_id','f.kind','f.quantity','f.borrower_statement_id',
                'f.source_incident_id','f.source_proof_path','f.source_proof_sha256',
                'f.source_proof_size','f.proposed_by','d.decision','d.decided_by'])
            ->map(function($row):array {
                $this->checkedProof((string)$row->source_proof_path,
                    (string)$row->source_proof_sha256,(int)$row->source_proof_size);
                return ['id'=>(string)$row->id,'itemId'=>(string)$row->source_item_id,
                    'kind'=>(string)$row->kind,'quantity'=>(int)$row->quantity,
                    'borrowerStatementId'=>(string)$row->borrower_statement_id,
                    'sourceIncidentId'=>(string)$row->source_incident_id,
                    'sha256'=>(string)$row->source_proof_sha256,
                    'proposedBy'=>(int)$row->proposed_by,'decision'=>$row->decision,
                    'decidedBy'=>$row->decided_by,'physicalReturnRecorded'=>false,
                    'stockRetired'=>false,'moneyPosted'=>false,'finalClosure'=>false];
            })->all();
    }
    /** Private, hash-verified signed source PDF/photo, audit every successful access. */
    public function sourceProof(User $actor,string $company,string $hall,string $findingId,bool $source):array {
        $this->gate();$this->ids($company,$hall,$findingId);
        $this->scope($actor,$company,$hall,$source);
        $finding=DB::table('hall_inventory_internal_physical_findings as f')
            ->join('hall_inventory_loan_drafts as l',function($j):void {
                $j->on('l.company_id','=','f.company_id')->on('l.id','=','f.loan_id');
            })->where('f.company_id',$company)->where('f.id',$findingId)
            ->where('l.direction','internal')
            ->where($source?'l.lending_hall_id':'l.borrowing_hall_id',$hall)
            ->first(['f.id','f.loan_id','f.source_proof_path','f.source_proof_sha256','f.source_proof_size']);
        if(!$finding)throw new AuthorizationException('Private original source proof is outside hall scope.');
        $bytes=$this->checkedProof((string)$finding->source_proof_path,
            (string)$finding->source_proof_sha256,(int)$finding->source_proof_size);
        $type=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(!in_array($type,['application/pdf','image/png','image/jpeg'],true))
            throw new ConflictHttpException('Original source proof format no longer valid.');
        $this->audit($actor,$company,'source_proof_read',$findingId,(string)$finding->loan_id,
            ['sourceProofSha256'=>$finding->source_proof_sha256]);
        return ['bytes'=>$bytes,'mime'=>$type,'name'=>'internal-source-proof-'.$findingId.
            ($type==='application/pdf'?'.pdf':($type==='image/png'?'.png':'.jpg'))];
    }
    private function audit(User $actor,string $company,string $action,string $id,string $loan,array $data):void {
        DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$company,
            'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.internal_physical_finding_'.$action,
            'entity_type'=>'hall_inventory_internal_physical_finding','entity_id'=>$id,
            'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loan],JSON_THROW_ON_ERROR)]);
    }
}
