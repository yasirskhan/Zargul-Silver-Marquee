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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Private owner-recorded outside partner correspondence. Stored bytes and a
 * separate owner review do NOT authenticate the partner, accept a debt,
 * authorize a settlement, or create any financial journal.
 */
final class HallInventoryFinancialCounterpartyEvidenceService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate():void
    {
        foreach(['loan_financial_claims_enabled','loan_partner_responses_enabled',
            'loan_incidents_enabled','loan_incident_reviews_enabled',
            'external_out_dispatch_enabled','external_out_return_enabled'] as $flag)
            if(!config('inventory_controls.'.$flag))
                throw new ConflictHttpException('Private partner response workflow is disabled.');
    }

    private function ids(string ...$values):void
    {
        foreach($values as $value)
            if(!Str::isUuid($value))throw new InvalidArgumentException('Invalid exact claim/evidence reference.');
    }

    private function authorize(User $actor,string $companyId,string $hallId):void
    {
        $hall=Hall::query()->where('company_id',$companyId)->where('id',$hallId)
            ->where('is_active',true)->first();
        if(!$hall||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
            !$this->access->canConfigureHall($actor,$hall))
            throw new AuthorizationException('Current owner/admin of original lending hall required.');
    }

    // Lock the company FIRST, as the NCL issuer does, so an evidence
    // transaction cannot append to a loan concurrently with final signing.
    private function lockCompany(string $companyId):void
    {
        if(!DB::table('companies')->where('id',$companyId)
            ->where('is_active',true)->lockForUpdate()->exists())
            throw new ConflictHttpException('Inactive company cannot amend loan correspondence.');
    }

    private function assertNotFinal(string $companyId,string $loanId):void
    {
        if(DB::table('hall_inventory_nonclean_final_closures')
            ->where('company_id',$companyId)->where('loan_id',$loanId)->exists())
            throw new ConflictHttpException('Final NCL certificate freezes partner correspondence.');
    }

    private function evidence(UploadedFile $file):array
    {
        $path=$file->getRealPath();$size=$file->getSize();
        if(!$file->isValid()||!$path||!is_file($path)||!is_int($size)||$size<1||$size>5242880)
            throw new InvalidArgumentException('A real PDF or photo no larger than 5 MB is required.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($mime,['application/pdf','image/jpeg','image/png'],true))
            throw new InvalidArgumentException('Unsupported counterparty evidence bytes.');
        $bytes=file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)!==$size)
            throw new ConflictHttpException('Private correspondence evidence could not be read.');
        return [$bytes,$size,(string)$mime,hash('sha256',$bytes)];
    }

    private function intact(object $row):string
    {
        $disk=Storage::disk('local');
        if(!$disk->exists((string)$row->evidence_path))
            throw new ConflictHttpException('Counterparty correspondence evidence is missing.');
        $bytes=$disk->get((string)$row->evidence_path);
        if(!is_string($bytes)||strlen($bytes)!==(int)$row->evidence_size||
            !hash_equals((string)$row->evidence_sha256,hash('sha256',$bytes)))
            throw new ConflictHttpException('Counterparty evidence integrity failure.');
        return $bytes;
    }

    public function record(User $actor,string $companyId,string $claimId,string $evidenceId,
        string $position,?int $counterofferMinor,UploadedFile $file):string
    {
        $this->gate();$this->ids($companyId,$claimId,$evidenceId);
        if(!in_array($position,['document_says_accepts','document_says_disputes',
            'counteroffer','other_document'],true)||
            ($position==='counteroffer' ? ($counterofferMinor===null||$counterofferMinor<1||
                $counterofferMinor>100000000000) : $counterofferMinor!==null))
            throw new InvalidArgumentException('Invalid documentary response or proposed counteroffer.');
        [$bytes,$size,$mime,$sha]=$this->evidence($file);
        $fingerprint=hash('sha256',json_encode([
            $companyId,$claimId,$position,$counterofferMinor,$sha,(int)$actor->getKey()
        ],JSON_THROW_ON_ERROR));
        $disk=Storage::disk('local');
        return DB::transaction(function()use($actor,$companyId,$claimId,$evidenceId,
            $position,$counterofferMinor,$bytes,$size,$mime,$sha,$fingerprint,$disk):string {
            $this->lockCompany($companyId);
            $claim=DB::table('hall_inventory_financial_claims')
                ->where('company_id',$companyId)->where('id',$claimId)
                ->lockForUpdate()->first();
            if(!$claim)throw new ConflictHttpException('Original provisionally assessed claim not found.');
            $this->authorize($actor,$companyId,(string)$claim->source_hall_id);
            $this->assertNotFinal($companyId,(string)$claim->loan_id);
            $decision=DB::table('hall_inventory_financial_claim_decisions')
                ->where('company_id',$companyId)->where('claim_id',$claimId)->first();
            if(!$decision||!in_array($decision->decision,['approved_for_collection','disputed'],true))
                throw new ConflictHttpException('Only independently reviewed active claims may receive correspondence.');
            $old=DB::table('hall_inventory_financial_counterparty_evidence')
                ->where('company_id',$companyId)->where('id',$evidenceId)->first();
            if($old){
                if((string)$old->claim_id!==$claimId ||
                    !hash_equals((string)$old->fingerprint,$fingerprint)||
                    (int)$old->recorded_by!==(int)$actor->getKey())
                    throw new ConflictHttpException('Conflicting partner evidence replay.');
                $this->intact($old);
                return $evidenceId;
            }
            if(DB::table('hall_inventory_financial_counterparty_evidence')
                ->where('company_id',$companyId)->where('claim_id',$claimId)
                ->where('fingerprint',$fingerprint)->exists())
                throw new ConflictHttpException('Duplicate counterparty correspondence already recorded.');
            $key='private/hall-inventory-financial-partner/'.$companyId.'/'.$claimId.'/'.$evidenceId;
            // Existing immutable bytes are never safe to overwrite or delete.
            if($disk->exists($key))
                throw new ConflictHttpException('Immutable partner evidence storage key already exists.');
            if(!$disk->put($key,$bytes))
                throw new ConflictHttpException('Private document custody write failed.');
            try {
                DB::table('hall_inventory_financial_counterparty_evidence')->insert([
                    'id'=>$evidenceId,'company_id'=>$companyId,'claim_id'=>$claimId,
                    'loan_id'=>$claim->loan_id,'source_hall_id'=>$claim->source_hall_id,
                    'currency'=>$claim->currency,'position'=>$position,
                    'counteroffer_minor'=>$counterofferMinor,
                    'evidence_sha256'=>$sha,'evidence_size'=>$size,
                    'evidence_mime'=>$mime,'evidence_path'=>$key,
                    'fingerprint'=>$fingerprint,'recorded_by'=>$actor->getKey()
                ]);
                $this->audit($actor,$companyId,'partner_correspondence_recorded',$evidenceId,
                    (string)$claim->loan_id,['claimId'=>$claimId,'position'=>$position,
                        'counterofferMinor'=>$counterofferMinor,'currency'=>$claim->currency,
                        'evidenceSha256'=>$sha,'authenticityVerified'=>false,'journalPosted'=>false]);
            }catch(\Throwable $e){
                $disk->delete($key);
                throw $e;
            }
            return $evidenceId;
        },3);
    }

    public function review(User $actor,string $companyId,string $evidenceId,
        string $reviewId,string $assessment,string $reason):string
    {
        $this->gate();$this->ids($companyId,$evidenceId,$reviewId);
        if(!in_array($assessment,['document_integrity_confirmed','insufficient_partner_proof'],true)||
            mb_strlen(trim($reason))<10||mb_strlen($reason)>500)
            throw new InvalidArgumentException('Independent documentary assessment needs an explanation.');
        $reason=trim($reason);
        $fingerprint=hash('sha256',json_encode([
            $companyId,$evidenceId,$assessment,$reason,(int)$actor->getKey()
        ],JSON_THROW_ON_ERROR));
        return DB::transaction(function()use($actor,$companyId,$evidenceId,
            $reviewId,$assessment,$reason,$fingerprint):string {
            $this->lockCompany($companyId);
            $row=DB::table('hall_inventory_financial_counterparty_evidence')
                ->where('company_id',$companyId)->where('id',$evidenceId)
                ->lockForUpdate()->first();
            if(!$row)throw new ConflictHttpException('Private correspondence not found.');
            $this->authorize($actor,$companyId,(string)$row->source_hall_id);
            $this->assertNotFinal($companyId,(string)$row->loan_id);
            $claim=DB::table('hall_inventory_financial_claims')
                ->where('company_id',$companyId)->where('id',$row->claim_id)->first();
            if(!$claim||(int)$row->recorded_by===(int)$actor->getKey()||
                (int)$claim->proposed_by===(int)$actor->getKey())
                throw new AuthorizationException('An independent owner/admin must assess correspondence.');
            $this->intact($row);
            $old=DB::table('hall_inventory_financial_counterparty_reviews')
                ->where('company_id',$companyId)->where('evidence_id',$evidenceId)->first();
            if($old){
                if((string)$old->id!==$reviewId||
                    (int)$old->reviewed_by!==(int)$actor->getKey()||
                    !hash_equals((string)$old->fingerprint,$fingerprint))
                    throw new ConflictHttpException('Counterparty correspondence already independently reviewed.');
                return $reviewId;
            }
            DB::table('hall_inventory_financial_counterparty_reviews')->insert([
                'id'=>$reviewId,'company_id'=>$companyId,'evidence_id'=>$evidenceId,
                'claim_id'=>$row->claim_id,'assessment'=>$assessment,
                'reason'=>$reason,'fingerprint'=>$fingerprint,
                'reviewed_by'=>$actor->getKey()
            ]);
            $this->audit($actor,$companyId,'partner_correspondence_reviewed',$reviewId,
                (string)$row->loan_id,['claimId'=>$row->claim_id,'evidenceId'=>$evidenceId,
                    'assessment'=>$assessment,'bytesIntact'=>true,
                    'partnerIdentityVerified'=>false,'financialJournalPosted'=>false]);
            return $reviewId;
        },3);
    }

    public function history(User $actor,string $companyId,string $hallId,string $loanId):array
    {
        $this->gate();$this->ids($companyId,$hallId,$loanId);
        $this->authorize($actor,$companyId,$hallId);
        $loan=DB::table('hall_inventory_loan_drafts')
            ->where('company_id',$companyId)->where('id',$loanId)
            ->where('lending_hall_id',$hallId)->where('direction','external_out')->first();
        if(!$loan)throw new AuthorizationException('Other hall private partner documents not visible.');
        return DB::table('hall_inventory_financial_counterparty_evidence as p')
            ->join('hall_inventory_financial_claims as c',function($j):void {
                $j->on('c.company_id','=','p.company_id')->on('c.id','=','p.claim_id');
            })
            ->leftJoin('hall_inventory_financial_counterparty_reviews as r',function($j):void {
                $j->on('r.company_id','=','p.company_id')->on('r.evidence_id','=','p.id');
            })
            ->where('p.company_id',$companyId)->where('p.source_hall_id',$hallId)
            ->where('p.loan_id',$loanId)->orderByDesc('p.created_at')
            ->orderByDesc('p.id')->limit(100)
            ->get(['p.id','p.claim_id','p.position','p.counteroffer_minor',
                'p.currency','p.evidence_sha256','p.recorded_by',
                'c.proposed_by','r.assessment','r.reviewed_by'])
            ->map(static fn($x):array=>[
                'id'=>(string)$x->id,'claimId'=>(string)$x->claim_id,
                'position'=>(string)$x->position,
                'counterofferMinor'=>$x->counteroffer_minor===null?null:(int)$x->counteroffer_minor,
                'currency'=>(string)$x->currency,'sha256'=>(string)$x->evidence_sha256,
                'recordedBy'=>(int)$x->recorded_by,'proposedBy'=>(int)$x->proposed_by,
                'assessment'=>$x->assessment,'reviewedBy'=>$x->reviewed_by,
                'partnerIdentityVerified'=>false,'paidMinor'=>0,'journalPosted'=>false
            ])->all();
    }

    public function download(User $actor,string $companyId,string $hallId,
        string $loanId,string $evidenceId):StreamedResponse
    {
        $this->gate();$this->ids($companyId,$hallId,$loanId,$evidenceId);
        $this->authorize($actor,$companyId,$hallId);
        $row=DB::table('hall_inventory_financial_counterparty_evidence')
            ->where('company_id',$companyId)->where('source_hall_id',$hallId)
            ->where('loan_id',$loanId)->where('id',$evidenceId)->first();
        if(!$row)throw new AuthorizationException('This document does not belong to the selected hall loan.');
        $bytes=$this->intact($row);
        $this->audit($actor,$companyId,'partner_correspondence_downloaded',$evidenceId,
            $loanId,['sha256'=>$row->evidence_sha256,'privateDownload'=>true]);
        $ext=match($row->evidence_mime){
            'application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg',
            default=>throw new ConflictHttpException('Unsupported stored private evidence type.')
        };
        return response()->streamDownload(static function()use($bytes):void{
            echo $bytes;
        },'inventory-partner-document-'.$evidenceId.'.'.$ext,[
            'Content-Type'=>$row->evidence_mime,
            'Cache-Control'=>'private, no-store',
            'Pragma'=>'no-cache',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    private function audit(User $actor,string $companyId,string $name,string $id,
        string $loanId,array $details):void
    {
        DB::table('audit_events')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$companyId,
            'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.'.$name,
            'entity_type'=>'hall_inventory_financial_counterparty_evidence',
            'entity_id'=>$id,'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
            'after_values'=>json_encode($details,JSON_THROW_ON_ERROR),
            'context'=>json_encode(['loanId'=>$loanId,'moneyPosted'=>false],
                JSON_THROW_ON_ERROR)
        ]);
    }
}
