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
/** Signed borrower testimony and independent source review only. Never posts stock, money or closure. */
final class HallInventoryInternalDiscrepancyService {
 public function __construct(private readonly TenantAccess $access){}
 private function gate():void {
  foreach(['loan_drafts_enabled','loan_dispatch_enabled','loan_receipt_enabled','loan_returns_enabled','internal_discrepancy_enabled'] as $flag)
   if(!config('inventory_controls.'.$flag))throw new ConflictHttpException('Internal discrepancy evidence not released.');
 }
 private function scope(User $actor,string $company,string $hall,bool $source):void {
  $h=Hall::query()->where('company_id',$company)->where('id',$hall)->where('is_active',true)->first();
  if(!$h||!Auth::check()||(int)Auth::id()!==(int)$actor->getKey()||
   !($source?$this->access->canConfigureHall($actor,$h):$this->access->canManageHallInventory($actor,$h)))
   throw new AuthorizationException('Current original loan hall authorization required.');
 }
 public function submit(User $actor,string $company,string $hall,string $loanId,string $itemId,string $id,
  string $kind,int $qty,string $reason,UploadedFile $evidence):string {
  $this->gate();
  foreach([$company,$hall,$loanId,$itemId,$id] as $uuid)if(!Str::isUuid($uuid))throw new InvalidArgumentException('Original custody UUID required.');
  $reason=trim($reason);
  if(!in_array($kind,['missing','dispute'],true)||$qty<1||$qty>100000000||mb_strlen($reason)<10||mb_strlen($reason)>500)
   throw new InvalidArgumentException('Signed missing/dispute reason and positive quantity required.');
  $this->scope($actor,$company,$hall,false);
  $path=$evidence->getRealPath();$size=$evidence->getSize();
  if(!$evidence->isValid()||!$path||!is_file($path)||!is_int($size)||$size<1||$size>5242880||
   !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),['application/pdf','image/png','image/jpeg'],true))
   throw new InvalidArgumentException('Signed PDF/photo evidence required.');
  $bytes=file_get_contents($path);
  if(!is_string($bytes)||strlen($bytes)!==$size)throw new ConflictHttpException('Original evidence unreadable.');
  $sha=hash('sha256',$bytes);
  $fingerprint=hash('sha256',json_encode([$company,$hall,$loanId,$itemId,(int)$actor->getKey(),$kind,$qty,$reason,$sha],JSON_THROW_ON_ERROR));
  return DB::transaction(function()use($actor,$company,$hall,$loanId,$itemId,$id,$kind,$qty,$reason,$bytes,$size,$sha,$fingerprint):string{
   $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$company)->where('id',$loanId)->lockForUpdate()->first();
   if(!$loan||$loan->direction!=='internal'||(string)$loan->borrowing_hall_id!==$hall)
    throw new AuthorizationException('Original receiving hall loan required.');
   if(DB::table('hall_inventory_internal_loan_closures')->where('company_id',$company)->where('loan_id',$loanId)->exists())
    throw new ConflictHttpException('Finalized internal loan evidence is closed.');
   $receipt=DB::table('hall_inventory_internal_loan_receipts')->where('company_id',$company)
    ->where('loan_id',$loanId)->where('receiving_hall_id',$hall)->first();
   if(!$receipt)throw new ConflictHttpException('Borrower custody was not independently received.');
   $line=DB::table('hall_inventory_internal_loan_receipt_lines')->where('company_id',$company)
    ->where('receipt_id',$receipt->id)->where('source_item_id',$itemId)->first();
   if(!$line||$qty>(int)$line->quantity)throw new ConflictHttpException('Discrepancy exceeds original receipt.');
   $old=DB::table('hall_inventory_internal_discrepancy_statements')->where('company_id',$company)->where('id',$id)->first();
   $disk=Storage::disk('local');
   if($old){
    if(!hash_equals((string)$old->fingerprint,$fingerprint)||!$disk->exists($old->evidence_storage_path))
      throw new ConflictHttpException('Conflicting or damaged signed evidence replay.');
    $saved=$disk->get($old->evidence_storage_path);
    if(!is_string($saved)||strlen($saved)!==(int)$old->evidence_size||
      !hash_equals((string)$old->evidence_sha256,hash('sha256',$saved)))
      throw new ConflictHttpException('Conflicting or damaged signed evidence replay.');
    return $id;
   }
   $key='private/hall-internal-discrepancy/'.$company.'/'.$loanId.'/'.$id;
   if($disk->exists($key))throw new ConflictHttpException('Evidence key already occupied.');
   if(!$disk->put($key,$bytes))throw new ConflictHttpException('Signed evidence storage failed.');
   try {
    DB::table('hall_inventory_internal_discrepancy_statements')->insert([
     'id'=>$id,'company_id'=>$company,'loan_id'=>$loanId,'receipt_id'=>$receipt->id,
     'borrowing_hall_id'=>$hall,'source_item_id'=>$itemId,'kind'=>$kind,'quantity'=>$qty,
     'reason'=>$reason,'fingerprint'=>$fingerprint,'evidence_sha256'=>$sha,'evidence_size'=>$size,
     'evidence_storage_path'=>$key,'submitted_by'=>$actor->getKey()]);
    $this->audit($actor,$company,'statement',$id,$loanId,['kind'=>$kind,'quantity'=>$qty,'evidenceSha256'=>$sha,'stockMoved'=>false,'moneyPosted'=>false]);
   }catch(\Throwable $e){$disk->delete($key);throw $e;}
   return $id;
  },3);
 }
 public function review(User $actor,string $company,string $hall,string $statementId,string $decisionId,string $decision,string $reason):string {
  $this->gate();
  foreach([$company,$hall,$statementId,$decisionId] as $id)if(!Str::isUuid($id))throw new InvalidArgumentException('Review UUID required.');
  $reason=trim($reason);
  if(!in_array($decision,['corroborated','disputed','requires_more_evidence'],true)||mb_strlen($reason)<10||mb_strlen($reason)>500)
   throw new InvalidArgumentException('Independent documented review required.');
  $this->scope($actor,$company,$hall,true);
  return DB::transaction(function()use($actor,$company,$hall,$statementId,$decisionId,$decision,$reason):string{
   // Match submission lock order (loan before statement) under concurrent
   // borrower retry and lender review to prevent a circular wait.
   $identity=DB::table('hall_inventory_internal_discrepancy_statements')
    ->where('company_id',$company)->where('id',$statementId)->first(['loan_id']);
   if(!$identity)throw new AuthorizationException('Signed statement is outside authorized scope.');
   $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$company)
    ->where('id',$identity->loan_id)->lockForUpdate()->first();
   $statement=DB::table('hall_inventory_internal_discrepancy_statements')
    ->where('company_id',$company)->where('id',$statementId)->where('loan_id',$identity->loan_id)
    ->lockForUpdate()->first();
   if(!$statement)throw new ConflictHttpException('Signed statement changed during review.');
   if(!$loan||$loan->direction!=='internal'||(string)$loan->lending_hall_id!==$hall)
    throw new AuthorizationException('Original lending hall reviewer required.');
   if((int)$statement->submitted_by===(int)$actor->getKey())
    throw new AuthorizationException('Borrower cannot approve their own statement.');
   if(DB::table('hall_inventory_internal_loan_closures')->where('company_id',$company)->where('loan_id',$loan->id)->exists())
    throw new ConflictHttpException('Finalized internal loan cannot be reviewed.');
   $disk=Storage::disk('local');
   if(!$disk->exists($statement->evidence_storage_path))throw new ConflictHttpException('Signed evidence missing.');
   $bytes=$disk->get($statement->evidence_storage_path);
   if(!is_string($bytes)||strlen($bytes)!==(int)$statement->evidence_size||
    !hash_equals((string)$statement->evidence_sha256,hash('sha256',$bytes)))
    throw new ConflictHttpException('Signed evidence integrity mismatch.');
   $fingerprint=hash('sha256',json_encode([$company,$statementId,(int)$actor->getKey(),$decision,$reason],JSON_THROW_ON_ERROR));
   $old=DB::table('hall_inventory_internal_discrepancy_decisions')->where('company_id',$company)
    ->where('statement_id',$statementId)->first();
   if($old){
    if($old->id!==$decisionId||!hash_equals($old->fingerprint,$fingerprint))
     throw new ConflictHttpException('Immutable review conflicts with prior decision.');
    return $decisionId;
   }
   DB::table('hall_inventory_internal_discrepancy_decisions')->insert([
    'id'=>$decisionId,'company_id'=>$company,'statement_id'=>$statementId,
    'decision'=>$decision,'reason'=>$reason,'fingerprint'=>$fingerprint,'reviewed_by'=>$actor->getKey()]);
   $this->audit($actor,$company,'decision',$decisionId,$loan->id,['statementId'=>$statementId,'decision'=>$decision,'stockMoved'=>false,'moneyPosted'=>false]);
   return $decisionId;
  },3);
 }
 public function history(User $actor,string $company,string $hall,string $loanId,bool $source):array {
  $this->gate();$this->scope($actor,$company,$hall,$source);
  $loan=DB::table('hall_inventory_loan_drafts')->where('company_id',$company)->where('id',$loanId)->where('direction','internal')
   ->where($source?'lending_hall_id':'borrowing_hall_id',$hall)->first();
  if(!$loan)throw new AuthorizationException('Original loan not accessible from this hall.');
  $disk=Storage::disk('local');
  return DB::table('hall_inventory_internal_discrepancy_statements as s')
   ->leftJoin('hall_inventory_internal_discrepancy_decisions as d',function($j):void{
    $j->on('d.company_id','=','s.company_id')->on('d.statement_id','=','s.id');
   })->where('s.company_id',$company)->where('s.loan_id',$loanId)->orderBy('s.created_at')
   ->get(['s.id','s.source_item_id','s.kind','s.quantity','s.reason','s.evidence_sha256',
    's.submitted_by','s.evidence_storage_path','s.evidence_size','d.decision','d.reviewed_by'])
   ->map(function($x)use($disk):array{
     if(!$disk->exists((string)$x->evidence_storage_path))
       throw new ConflictHttpException('Original signed borrower statement evidence missing.');
     $bytes=$disk->get((string)$x->evidence_storage_path);
     if(!is_string($bytes)||strlen($bytes)!==(int)$x->evidence_size||
       !hash_equals((string)$x->evidence_sha256,hash('sha256',$bytes)))
       throw new ConflictHttpException('Original signed borrower statement integrity failed.');
     return [
     'id'=>$x->id,'itemId'=>$x->source_item_id,'kind'=>$x->kind,'quantity'=>(int)$x->quantity,
     'reason'=>$x->reason,'sha256'=>$x->evidence_sha256,'submittedBy'=>(int)$x->submitted_by,
     'decision'=>$x->decision,'reviewedBy'=>$x->reviewed_by];})->all();
 }
 /** Authenticated original signed borrower PDF/photo. The immutable statement is not a physical return. */
 public function proof(User $actor,string $company,string $hall,string $statementId,bool $source):array {
  $this->gate();
  foreach([$company,$hall,$statementId] as $id)
   if(!Str::isUuid($id))throw new InvalidArgumentException('Original signed statement scope UUID required.');
  $this->scope($actor,$company,$hall,$source);
  $statement=DB::table('hall_inventory_internal_discrepancy_statements as s')
   ->join('hall_inventory_loan_drafts as l',function($j):void {
    $j->on('l.company_id','=','s.company_id')->on('l.id','=','s.loan_id');
   })->where('s.company_id',$company)->where('s.id',$statementId)
   ->where('l.direction','internal')
   ->where($source?'l.lending_hall_id':'l.borrowing_hall_id',$hall)
   ->first(['s.id','s.loan_id','s.evidence_storage_path','s.evidence_size','s.evidence_sha256']);
  if(!$statement)throw new AuthorizationException('Original signed borrower evidence outside your hall.');
  $path=(string)$statement->evidence_storage_path;
  $prefix='private/hall-internal-discrepancy/'.$company.'/'.$statement->loan_id.'/'.$statementId;
  if(!hash_equals($prefix,$path)||!Storage::disk('local')->exists($path))
   throw new ConflictHttpException('Original signed borrower proof is missing.');
  $bytes=Storage::disk('local')->get($path);
  if(!is_string($bytes)||strlen($bytes)!==(int)$statement->evidence_size||
   !hash_equals((string)$statement->evidence_sha256,hash('sha256',$bytes)))
   throw new ConflictHttpException('Original signed borrower proof failed integrity check.');
  $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
  if(!in_array($mime,['application/pdf','image/png','image/jpeg'],true))
   throw new ConflictHttpException('Stored borrower evidence format invalid.');
  $this->audit($actor,$company,'borrower_proof_read',$statementId,(string)$statement->loan_id,
   ['proofSha256'=>$statement->evidence_sha256,'physicalReturnRecorded'=>false,'moneyPosted'=>false]);
  return ['bytes'=>$bytes,'mime'=>$mime,'name'=>'internal-borrower-signed-'.$statementId.
   ($mime==='application/pdf'?'.pdf':($mime==='image/png'?'.png':'.jpg'))];
 }
 private function audit(User $actor,string $company,string $action,string $id,string $loan,array $data):void {
  DB::table('audit_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$company,
   'actor_id'=>$actor->getKey(),'action'=>'hall_inventory.internal_discrepancy_'.$action,
   'entity_type'=>'hall_inventory_internal_discrepancy','entity_id'=>$id,
   'before_values'=>json_encode([],JSON_THROW_ON_ERROR),
   'after_values'=>json_encode($data,JSON_THROW_ON_ERROR),
   'context'=>json_encode(['loanId'=>$loan],JSON_THROW_ON_ERROR)]);
 }
}
