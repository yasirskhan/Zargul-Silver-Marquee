<?php
namespace App\Services;

use App\Models\{Hall,User};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth,DB,Storage};
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Auditable correction to documentary findings, never return, retirement, debt forgiveness or closure. */
final class HallInventoryInternalFindingCorrectionService {
 public function __construct(private readonly TenantAccess $access){}
 private function gated():void {
  foreach(['internal_physical_finding_enabled','internal_discrepancy_enabled',
   'loan_dispatch_enabled','loan_receipt_enabled','loan_returns_enabled'] as $flag)
   if(!config('inventory_controls.'.$flag))
    throw new ConflictHttpException('Immutable internal discrepancy correction is not released.');
 }
 private function ids(string ...$ids):void {
  foreach($ids as $id)if(!Str::isUuid($id))throw new InvalidArgumentException('Original loan UUID required.');
 }
 private function scope(User $actor,string $company,string $hall):void {
  $h=Hall::query()->where('company_id',$company)->where('id',$hall)->where('is_active',true)->first();
  if(!$h||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
   !$this->access->canConfigureHall($actor,$h))
   throw new AuthorizationException('Only a currently authorized original source owner/admin may correct evidence.');
 }
 private function loan(string $company,string $hall,string $loanId):object {
  $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$company)
   ->where('id',$loanId)->lockForUpdate()->first();
  if(!$loan||$loan->direction!=='internal'||(string)$loan->lending_hall_id!==$hall)
   throw new AuthorizationException('Original lending hall loan mismatch.');
  if(DB::table('hall_inventory_internal_loan_closures')->where('company_id',$company)
   ->where('loan_id',$loanId)->exists()||
   DB::table('hall_inventory_nonclean_final_closures')->where('company_id',$company)
   ->where('loan_id',$loanId)->exists())
   throw new ConflictHttpException('Finalized original loan evidence cannot be corrected.');
  return $loan;
 }
 private function proof(object $row):array {
  $prefix='private/hall-internal-finding-correction/'.$row->company_id.'/'.$row->loan_id.'/'.$row->id;
  if(!hash_equals($prefix,(string)$row->proof_path)||!Storage::disk('local')->exists($prefix))
   throw new ConflictHttpException('Private correction proof is missing.');
  $bytes=Storage::disk('local')->get($prefix);
  if(!is_string($bytes)||strlen($bytes)!==(int)$row->proof_size||
   !hash_equals((string)$row->proof_sha256,hash('sha256',$bytes)))
   throw new ConflictHttpException('Private immutable correction proof tampered.');
  $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
  if(!in_array($mime,['application/pdf','image/png','image/jpeg'],true))
   throw new ConflictHttpException('Correction proof format invalid.');
  return [$bytes,$mime];
 }
 private function audited(User $actor,string $company,string $kind,string $id,string $loan,array $data):void {
  DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$company,
   'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.internal_finding_correction_'.$kind,
   'entity_type'=>'hall_inventory_internal_finding_correction','entity_id'=>$id,
   'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
   'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
   'context'=>json_encode(['loanId'=>$loan,'physicalMovement'=>false,'financialJournal'=>false],JSON_THROW_ON_ERROR)]);
 }
 public function propose(User $actor,string $company,string $hall,string $findingId,
  string $requestId,string $reason,UploadedFile $file):string {
  $this->gated();$this->ids($company,$hall,$findingId,$requestId);$reason=trim($reason);
  if(mb_strlen($reason)<10||mb_strlen($reason)>500)
   throw new InvalidArgumentException('Original discrepancy correction reason required.');
  $path=$file->getRealPath();$size=$file->getSize();
  if(!$file->isValid()||!$path||!is_file($path)||!is_int($size)||
   $size<1||$size>5242880||
   !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),
    ['application/pdf','image/jpeg','image/png'],true))
   throw new InvalidArgumentException('Signed independent correction PDF/photo required.');
  $bytes=file_get_contents($path);
  if(!is_string($bytes)||strlen($bytes)!==$size)
   throw new ConflictHttpException('Signed correction proof unreadable.');
  $sha=hash('sha256',$bytes);
  return DB::transaction(function()use($actor,$company,$hall,$findingId,$requestId,$reason,$size,$bytes,$sha):string{
   // Original loan row is first lock shared with shipment/acceptance,
   // incident, finding and final closure transactions.
   $ref=DB::table('hall_inventory_internal_physical_findings')->where('company_id',$company)
    ->where('id',$findingId)->first(['loan_id']);
   if(!$ref)throw new AuthorizationException('Original finding is not in scope.');
   $this->loan($company,$hall,(string)$ref->loan_id);$this->scope($actor,$company,$hall);
   $finding=DB::table('hall_inventory_internal_physical_findings')->where('company_id',$company)
    ->where('id',$findingId)->where('source_hall_id',$hall)->first();
   if(!$finding)throw new AuthorizationException('Original source finding mismatch.');
   $originalDecision=DB::table('hall_inventory_internal_physical_finding_decisions')
    ->where('company_id',$company)->where('finding_id',$findingId)->first(['decision']);
   if(!$originalDecision||$originalDecision->decision!=='confirmed_outstanding')
    throw new ConflictHttpException('Only independently confirmed outstanding original finding can be corrected.');
   $fingerprint=hash('sha256',json_encode([$company,$findingId,(int)$actor->getKey(),
    $reason,$sha],JSON_THROW_ON_ERROR));
   $previous=DB::table('hall_inventory_internal_finding_corrections')
    ->where('company_id',$company)->where('finding_id',$findingId)->first();
   if($previous){
    if((string)$previous->id!==$requestId||!hash_equals((string)$previous->fingerprint,$fingerprint))
     throw new ConflictHttpException('Existing signed correction cannot be replaced.');
    $this->proof($previous);return $requestId;
   }
   $key='private/hall-internal-finding-correction/'.$company.'/'.$ref->loan_id.'/'.$requestId;
   $disk=Storage::disk('local');
   if($disk->exists($key))throw new ConflictHttpException('Private signed correction key already occupied.');
   if(!$disk->put($key,$bytes))throw new ConflictHttpException('Private signed correction storage failed.');
   try{
    DB::table('hall_inventory_internal_finding_corrections')->insert([
     'id'=>$requestId,'company_id'=>$company,'loan_id'=>$ref->loan_id,'source_hall_id'=>$hall,
     'finding_id'=>$findingId,'reason'=>$reason,'fingerprint'=>$fingerprint,
     'proof_sha256'=>$sha,'proof_size'=>$size,'proof_path'=>$key,
     'proposed_by'=>$actor->getKey()]);
    $this->audited($actor,$company,'proposed',$requestId,(string)$ref->loan_id,[
     'findingId'=>$findingId,'proofSha256'=>$sha,'noStockOrMoneyMutation'=>true]);
   }catch(\Throwable $e){$disk->delete($key);throw $e;}
   return $requestId;
  },3);
 }
 public function review(User $actor,string $company,string $hall,string $correctionId,
  string $reviewId,string $decision,string $reason):string {
  $this->gated();$this->ids($company,$hall,$correctionId,$reviewId);$reason=trim($reason);
  if(!in_array($decision,['superseded','rejected'],true)||mb_strlen($reason)<10||mb_strlen($reason)>500)
   throw new InvalidArgumentException('Independent correction decision and reason required.');
  return DB::transaction(function()use($actor,$company,$hall,$correctionId,$reviewId,$decision,$reason):string {
   $ref=DB::table('hall_inventory_internal_finding_corrections')->where('company_id',$company)
    ->where('id',$correctionId)->first(['loan_id']);
   if(!$ref)throw new AuthorizationException('Signed correction outside original company scope.');
   $this->loan($company,$hall,(string)$ref->loan_id);$this->scope($actor,$company,$hall);
   $correction=DB::table('hall_inventory_internal_finding_corrections')
    ->where('company_id',$company)->where('id',$correctionId)->where('source_hall_id',$hall)
    ->lockForUpdate()->first();
   if(!$correction)throw new AuthorizationException('Correction is not from original lending hall.');
   $original=DB::table('hall_inventory_internal_physical_findings')->where('company_id',$company)
    ->where('id',$correction->finding_id)->where('loan_id',$ref->loan_id)->first();
   $originalDecision=DB::table('hall_inventory_internal_physical_finding_decisions')
    ->where('company_id',$company)->where('finding_id',$correction->finding_id)->first();
   if(!$original||!$originalDecision||$originalDecision->decision!=='confirmed_outstanding')
    throw new ConflictHttpException('Correction requires an independently confirmed outstanding physical finding.');
   // Third-person safeguard: correction approver cannot be the author of
   // either current correction or original finding, or original reviewer.
   if(in_array((int)$actor->getKey(),[(int)$correction->proposed_by,
    (int)$original->proposed_by,(int)$originalDecision->decided_by],true))
    throw new AuthorizationException('Third independent source owner/admin must review correction.');
   $this->proof($correction);
   $fingerprint=hash('sha256',json_encode([$company,$correctionId,
    (int)$actor->getKey(),$decision,$reason],JSON_THROW_ON_ERROR));
   $old=DB::table('hall_inventory_internal_finding_correction_reviews')
    ->where('company_id',$company)->where('correction_id',$correctionId)->first();
   if($old){
    if((string)$old->id!==$reviewId||!hash_equals((string)$old->fingerprint,$fingerprint))
     throw new ConflictHttpException('Immutable correction review already completed.');
    return $reviewId;
   }
   DB::table('hall_inventory_internal_finding_correction_reviews')->insert([
    'id'=>$reviewId,'company_id'=>$company,'correction_id'=>$correctionId,
    'decision'=>$decision,'reason'=>$reason,'fingerprint'=>$fingerprint,
    'reviewed_by'=>$actor->getKey()]);
   $this->audited($actor,$company,'reviewed',$reviewId,(string)$ref->loan_id,[
    'correctionId'=>$correctionId,'findingId'=>$correction->finding_id,
    'decision'=>$decision,'noStockOrMoneyMutation'=>true]);
   return $reviewId;
  },3);
 }
 public function history(User $actor,string $company,string $hall,string $loanId):array {
  $this->gated();$this->ids($company,$hall,$loanId);$this->scope($actor,$company,$hall);
  $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$company)
   ->where('id',$loanId)->where('direction','internal')->where('lending_hall_id',$hall)->first();
  if(!$loan)throw new AuthorizationException('Correction history denied outside source hall.');
  return DB::table('hall_inventory_internal_finding_corrections as c')
   ->leftJoin('hall_inventory_internal_finding_correction_reviews as r',function($j):void {
    $j->on('r.company_id','=','c.company_id')->on('r.correction_id','=','c.id');
   })->where('c.company_id',$company)->where('c.loan_id',$loanId)->orderBy('c.created_at')
   ->get(['c.id','c.finding_id','c.reason','c.proof_sha256','c.proof_size','c.proof_path',
    'c.loan_id','c.company_id','c.proposed_by','r.decision','r.reviewed_by'])
   ->map(function($row):array{
    $this->proof($row);
    return ['id'=>(string)$row->id,'findingId'=>(string)$row->finding_id,
     'reason'=>(string)$row->reason,'sha256'=>(string)$row->proof_sha256,
     'proposedBy'=>(int)$row->proposed_by,'decision'=>$row->decision,
     'reviewedBy'=>$row->reviewed_by,'changesStock'=>false,'changesMoney'=>false];
   })->all();
 }
 public function signedProof(User $actor,string $company,string $hall,string $correctionId):array {
  $this->gated();$this->ids($company,$hall,$correctionId);$this->scope($actor,$company,$hall);
  $correction=DB::table('hall_inventory_internal_finding_corrections as c')
   ->join('hall_inventory_loan_drafts as l',function($j):void {
    $j->on('l.company_id','=','c.company_id')->on('l.id','=','c.loan_id');
   })->where('c.company_id',$company)->where('c.id',$correctionId)
   ->where('l.direction','internal')->where('c.source_hall_id',$hall)->first(['c.*']);
  if(!$correction)throw new AuthorizationException('Private source correction proof denied.');
  [$bytes,$mime]=$this->proof($correction);
  $this->audited($actor,$company,'proof_read',$correctionId,(string)$correction->loan_id,
   ['proofSha256'=>$correction->proof_sha256]);
  return ['bytes'=>$bytes,'mime'=>$mime,'name'=>'internal-correction-'.$correctionId.
   ($mime==='application/pdf'?'.pdf':($mime==='image/png'?'.png':'.jpg'))];
 }
}