<?php
declare(strict_types=1);
use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\CentralStaffCredentialVerifier;
use Hafzatech\Central\CentralStaffLoginRateLimiter;
use Hafzatech\Central\CentralStaffMfaChallenge;
use Hafzatech\Central\CentralStaffPasswordVerifier;
use Hafzatech\Central\CentralStaffSessionAuthorization;
use Hafzatech\Central\CentralStaffSessionService;
use Hafzatech\Central\CentralVendorVerifiedReversalService;
use Hafzatech\Central\CentralStaffInvoicePaymentOverview;
use Hafzatech\Central\CentralStaffPaymentEvidenceQueue;
use Hafzatech\Central\CentralStaffPaymentEvidenceReviewService;
use Hafzatech\Central\CentralStaffCsrfTokenService;
use Hafzatech\Central\CentralStaffProtectedHttpBoundary;
use Hafzatech\Central\CentralStaffPaymentEvidenceHttpAdapter;
use Hafzatech\Central\PaidInvoiceEligibility;
use Hafzatech\Central\RegistrationError;
foreach (['InstallationRegistry','StaffRolePermissions','CentralStaffAccess',
    'PaidInvoiceEligibility','CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
    'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
    'CentralStaffLoginRateLimiter','CentralStaffSessionService',
    'CentralStaffSessionAuthorization','CentralVendorVerifiedReversalService',
    'CentralStaffInvoicePaymentOverview','CentralStaffPaymentEvidenceQueue',
    'CentralStaffPaymentEvidenceReviewService','CentralStaffCsrfTokenService',
    'CentralStaffHttpPolicy','CentralStaffProtectedHttpBoundary',
    'CentralStaffPaymentEvidenceHttpAdapter'] as $name) {
    require dirname(__DIR__).'/src/'.$name.'.php';
}
function reverseCheck(bool $v,string $msg):void{
    if (!$v)throw new RuntimeException('Verified vendor reversal: '.$msg);
}
function reverseDeny(callable $fn,string $expected):void{
    try {$fn();} catch(RegistrationError $e) {
        reverseCheck($e->errorCode===$expected,
            'expected '.$expected.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Verified vendor reversal: expected '.$expected);
}
function reverseId():string{
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
        substr($h,16,4).'-'.substr($h,20);
}
function reverseTotp(string $seed):string{
    $n=intdiv(time(),30);
    $mac=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),$seed,true);
    $offset=ord($mac[19])&15;
    return str_pad((string)((unpack('N',substr($mac,$offset,4))[1]&0x7fffffff)%1000000),
        6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
reverseCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'isolated vendor PostgreSQL test database');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer=reverseId();$foreign=reverseId();$billing=reverseId();
$officer=reverseId();$foreignBilling=reverseId();$plan=reverseId();
$invoice=reverseId();$account=reverseId();$settlement=reverseId();
$seed='12345678901234567890';$password='synthetic-reversal-password';
$c=$db->prepare('INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)');
$c->execute(['id'=>$customer,'name'=>'Synthetic Vendor Refund Customer']);
$c->execute(['id'=>$foreign,'name'=>'Synthetic Foreign Customer']);
$u=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic Settlement Reviewer',:hash,decode(:seed,'hex'),now(),'active')");
$g=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,customer_scope_id,reason)
 VALUES(:id,:staff,:role,:scope,'Synthetic reversal permission')");
foreach([
 [$billing,'billing','billing_officer',null],
 [$officer,'officer','license_officer',null],
 [$foreignBilling,'foreign','billing_officer',$foreign]
] as [$id,$label,$role,$scope]){
    $u->execute(['id'=>$id,'email'=>'reversal-'.$label.'@example.test',
        'hash'=>password_hash($password,PASSWORD_ARGON2ID),'seed'=>bin2hex($seed)]);
    $g->execute(['id'=>reverseId(),'staff'=>$id,'role'=>$role,'scope'=>$scope]);
}
$db->prepare("INSERT INTO central_vendor_receiving_accounts
 (id,provider_kind,display_label,country_code,receiving_currency,
 encrypted_instructions,status,verified_at,verified_by)
 VALUES(:id,'domestic_bank','Synthetic Verified Reversal Bank','PK','PKR',
 decode(:cipher,'hex'),'verified',now(),:billing)")
 ->execute(['id'=>$account,'cipher'=>bin2hex(random_bytes(32)),'billing'=>$billing]);
$db->prepare("INSERT INTO central_customer_provider_assignments
 (id,customer_id,receiving_account_id,enabled)
 VALUES(:id,:customer,:account,true)")
 ->execute(['id'=>reverseId(),'customer'=>$customer,'account'=>$account]);
$db->prepare("INSERT INTO central_vendor_plan_versions
 (id,plan_code,version,term_months,currency,price_minor)
 VALUES(:id,:code,1,12,'PKR',10000)")
 ->execute(['id'=>$plan,'code'=>'reversal-'.substr($plan,0,8)]);
$db->prepare("INSERT INTO central_vendor_invoices
 (id,customer_id,plan_version_id,invoice_number,currency,total_minor,
 receiving_account_id,encrypted_instructions_snapshot,status,issued_at)
 VALUES(:id,:customer,:plan,:number,'PKR',10000,:account,
 decode(:cipher,'hex'),'issued',now())")
 ->execute(['id'=>$invoice,'customer'=>$customer,'plan'=>$plan,
 'number'=>'REVERSAL-'.substr($invoice,0,8),'account'=>$account,
 'cipher'=>bin2hex(random_bytes(32))]);
$insertSettlement=$db->prepare("INSERT INTO central_vendor_verified_settlements
 (id,invoice_id,receiving_account_id,currency,amount_minor,
 external_settlement_reference,verified_by,verified_at)
 VALUES(:id,:invoice,:account,'PKR',:amount,:ref,:billing,now())");
$insertSettlement->execute(['id'=>$settlement,'invoice'=>$invoice,
 'account'=>$account,'amount'=>10000,'ref'=>'synthetic-deposit-'.$settlement,'billing'=>$billing]);
$guard=new PaidInvoiceEligibility($db);
reverseCheck($guard->isEligible($invoice,$officer),'initial independently paid invoice eligible');
$sessions=new CentralStaffSessionService($db,
 new CentralStaffCredentialVerifier(
  new CentralStaffPasswordVerifier($db),
  new CentralStaffMfaChallenge($db,static fn(string $cipher):string=>$cipher)
 ),new CentralStaffLoginRateLimiter($db,str_repeat('r',32)));
$service=new CentralVendorVerifiedReversalService($db,$sessions,
 new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db)));
$otp=reverseTotp($seed);
$billingBearer=$sessions->login('reversal-billing@example.test',$password,$otp,'192.0.2.125');
$officerBearer=$sessions->login('reversal-officer@example.test',$password,$otp,'192.0.2.126');
$foreignBearer=$sessions->login('reversal-foreign@example.test',$password,$otp,'192.0.2.127');
reverseCheck(is_string($billingBearer)&&is_string($officerBearer)&&
 is_string($foreignBearer),'three real vendor MFA sessions');
$args=[$settlement,2500,'refund','BANK-REFUND-TEST-1','Bank verified partial repayment'];
reverseDeny(fn()=>$service->record(str_repeat('0',64),...$args),'staff_session_required');
reverseDeny(fn()=>$service->record($officerBearer,...$args),'billing_role_required');
reverseDeny(fn()=>$service->record($foreignBearer,...$args),'billing_role_required');
reverseDeny(fn()=>$service->record($billingBearer,$settlement,0,'refund',
 'BANK-REFUND-TEST-1','Bank verified partial repayment'),'invalid_verified_reversal');
reverseDeny(fn()=>$service->record($billingBearer,$settlement,1,'other',
 'BANK-REFUND-TEST-1','Bank verified partial repayment'),'invalid_verified_reversal');
$first=$service->record($billingBearer,...$args);
reverseCheck($first['amount_minor']===2500&&$first['kind']==='refund',
 'verified reversal recorded as immutable evidence without sending payment');
reverseCheck(!$guard->isEligible($invoice,$officer),
 'partial verified reversal reduces future paid license eligibility');
$claimId=reverseId();
$db->prepare("INSERT INTO central_vendor_payment_evidence
 (id,invoice_id,claimed_reference,attachment_private_object_key)
 VALUES(:id,:invoice,:claim,:private)")
 ->execute(['id'=>$claimId,'invoice'=>$invoice,
    'claim'=>str_repeat('CUSTOMER-CLAIM-UNVERIFIED-',12),
    'private'=>'private/vendor-evidence/never-expose-object-key']);
$evidence=new CentralStaffPaymentEvidenceQueue($db,
 new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db)));
reverseDeny(fn()=>$evidence->listPending(str_repeat('0',64),$customer),
 'billing_role_required');
reverseDeny(fn()=>$evidence->listPending($officerBearer,$customer),
 'billing_role_required');
reverseDeny(fn()=>$evidence->listPending($foreignBearer,$customer),
 'billing_role_required');
reverseDeny(fn()=>$evidence->listPending($billingBearer,'bad-customer'),
 'invalid_payment_evidence_query');
reverseDeny(fn()=>$evidence->listPending($billingBearer,$customer,-1),
 'invalid_payment_evidence_query');
reverseDeny(fn()=>$evidence->listPending($billingBearer,$customer,0,101),
 'invalid_payment_evidence_query');
$evidenceRows=$evidence->listPending($billingBearer,$customer,0,1);
reverseCheck(count($evidenceRows)===1 && $evidenceRows[0]['evidence_id']===$claimId,
 'active scoped Billing Officer sees exactly one pending customer claim');
reverseCheck(array_keys($evidenceRows[0])===[
 'evidence_id','invoice_id','invoice_number','currency','total_minor',
 'claimed_reference','created_at','verification_state'
 ],'allowlisted queue fields exclude private attachment and payment account');
reverseCheck($evidenceRows[0]['verification_state']==='customer_claim_unverified'
 && strlen($evidenceRows[0]['claimed_reference'])<=160,
 'long customer-supplied reference is bounded and explicitly unverified');
reverseCheck($evidence->listPending($billingBearer,$customer,1,1)===[],
 'bounded pagination excludes already read customer claim');
reverseCheck(!$guard->isEligible($invoice,$officer),
 'customer uploaded evidence cannot reverse negative paid-license eligibility');
$review=new CentralStaffPaymentEvidenceReviewService($db,$sessions,
 new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db)));
$overview=new CentralStaffInvoicePaymentOverview($db,$sessions,
 new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db)));
$decisionReason='Externally assessed claim; screenshot is NOT verified bank funds';
// A future private staff portal can ONLY enter through this exact
// same-origin, CSRF and MFA protected internal adapter. No route is exposed.
$csrfTokens=new CentralStaffCsrfTokenService($db,$sessions);
$csrfToken=$csrfTokens->issue($billingBearer);
reverseCheck(is_string($csrfToken),'session-bound CSRF issue after real vendor MFA');
$httpBoundary=new CentralStaffProtectedHttpBoundary(
 $sessions,$csrfTokens,
 new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db)),true);
$httpOff=new CentralStaffPaymentEvidenceHttpAdapter(
 $db,$httpBoundary,$evidence,$review,$overview);
$httpOn=new CentralStaffPaymentEvidenceHttpAdapter(
 $db,$httpBoundary,$evidence,$review,$overview,true);
$httpRequest=[
 'HTTPS'=>'on','SERVER_PORT'=>'443',
 'HTTP_HOST'=>'lichall.hafzatech.com',
 'REQUEST_METHOD'=>'POST',
 'REQUEST_URI'=>'/staff/billing/payment-evidence/pending',
 'HTTP_ORIGIN'=>'https://lichall.hafzatech.com',
 'HTTP_SEC_FETCH_SITE'=>'same-origin',
 'REMOTE_ADDR'=>'192.0.2.49'
];
reverseDeny(fn()=>$httpOff->pending($httpRequest,$billingBearer,$csrfToken,$customer),
 'staff_http_not_enabled');
reverseCheck(count($httpOn->pending($httpRequest,$billingBearer,$csrfToken,$customer))===1,
 'internal protected billing evidence POST allows one scoped pending claim');
$wrongOrigin=$httpRequest;
$wrongOrigin['HTTP_ORIGIN']='https://evil.example.test';
reverseDeny(fn()=>$httpOn->pending($wrongOrigin,$billingBearer,$csrfToken,$customer),
 'staff_http_denied');
$wrongMethod=$httpRequest;$wrongMethod['REQUEST_METHOD']='GET';
reverseDeny(fn()=>$httpOn->pending($wrongMethod,$billingBearer,$csrfToken,$customer),
 'staff_http_denied');
$wrongPath=$httpRequest;$wrongPath['REQUEST_URI']='/staff/billing/other';
reverseDeny(fn()=>$httpOn->pending($wrongPath,$billingBearer,$csrfToken,$customer),
 'staff_http_denied');
reverseDeny(fn()=>$httpOn->pending($httpRequest,$officerBearer,$csrfToken,$customer),
 'staff_http_denied');
reverseDeny(fn()=>$httpOn->pending($httpRequest,$foreignBearer,$csrfToken,$customer),
 'staff_http_denied');
reverseDeny(fn()=>$httpOn->pending($httpRequest,$billingBearer,str_repeat('0',64),$customer),
 'staff_http_denied');
reverseDeny(fn()=>$httpOn->pending($httpRequest,str_repeat('0',64),$csrfToken,$customer),
 'staff_http_denied');
$invoiceHttp=$httpRequest;
$invoiceHttp['REQUEST_URI']='/staff/billing/invoice/status';
reverseDeny(fn()=>$httpOff->invoiceStatus($invoiceHttp,$billingBearer,
 $csrfToken,$customer,$invoice),'staff_http_not_enabled');
$invoiceSnapshot=$httpOn->invoiceStatus($invoiceHttp,$billingBearer,
 $csrfToken,$customer,$invoice);
reverseCheck($invoiceSnapshot['invoice_id']===$invoice &&
 $invoiceSnapshot['verified_minor']==='10000' &&
 $invoiceSnapshot['reversed_minor']==='2500' &&
 $invoiceSnapshot['net_minor']==='7500' &&
 $invoiceSnapshot['net_covered']===false,
 'billing staff portal reports net independent settlement not uploaded claims');
reverseDeny(fn()=>$httpOn->invoiceStatus($invoiceHttp,$billingBearer,
 $csrfToken,$foreign,$invoice),'invoice_not_found');
reverseDeny(fn()=>$httpOn->invoiceStatus($httpRequest,$billingBearer,
 $csrfToken,$customer,$invoice),'staff_http_denied');
reverseDeny(fn()=>$httpOn->invoiceStatus($invoiceHttp,$foreignBearer,
 $csrfToken,$customer,$invoice),'staff_http_denied');
reverseDeny(fn()=>$httpOn->invoiceStatus($invoiceHttp,$billingBearer,
 str_repeat('0',64),$customer,$invoice),'staff_http_denied');
$decisionHttp=$httpRequest;
$decisionHttp['REQUEST_URI']='/staff/billing/payment-evidence/decide';
reverseDeny(fn()=>$httpOff->decide($decisionHttp,$billingBearer,$csrfToken,
 $customer,$claimId,'reviewed',$decisionReason),'staff_http_not_enabled');
reverseDeny(fn()=>$httpOn->decide($decisionHttp,$billingBearer,$csrfToken,
 $foreign,$claimId,'reviewed',$decisionReason),'payment_evidence_not_found');
reverseDeny(fn()=>$httpOn->decide($httpRequest,$billingBearer,$csrfToken,
 $customer,$claimId,'reviewed',$decisionReason),'staff_http_denied');

reverseDeny(fn()=>$review->decide(str_repeat('0',64),$claimId,'reviewed',$decisionReason),
 'staff_session_required');
reverseDeny(fn()=>$review->decide($officerBearer,$claimId,'reviewed',$decisionReason),
 'billing_role_required');
reverseDeny(fn()=>$review->decide($foreignBearer,$claimId,'reviewed',$decisionReason),
 'billing_role_required');
reverseDeny(fn()=>$review->decide($billingBearer,$claimId,'paid',$decisionReason),
 'invalid_payment_evidence_review');
reverseDeny(fn()=>$review->decide($billingBearer,$claimId,'reviewed','short'),
 'invalid_payment_evidence_review');
reverseDeny(fn()=>$review->decide($billingBearer,reverseId(),'reviewed',$decisionReason),
 'payment_evidence_not_found');
$reviewed=$httpOn->decide($decisionHttp,$billingBearer,$csrfToken,
 $customer,$claimId,'reviewed',$decisionReason);
reverseCheck($reviewed['evidence_id']===$claimId &&
 $reviewed['decision']==='reviewed' && $reviewed['reviewed_by']===$billing,
 'authorized billing session records a reviewed claim without creating settlement');
reverseCheck($review->decide($billingBearer,$claimId,'reviewed',$decisionReason)===$reviewed,
 'exact same billing actor and review reason idempotently returns prior decision');
reverseDeny(fn()=>$review->decide($billingBearer,$claimId,'rejected',$decisionReason),
 'payment_evidence_already_decided');
reverseCheck($evidence->listPending($billingBearer,$customer)===[],
 'reviewed customer claim no longer appears pending without a settlement');
$reviewCount=$db->prepare('SELECT count(*) FROM central_vendor_payment_evidence_reviews
 WHERE evidence_id=:id AND reviewer_id=:reviewer');
$reviewCount->execute(['id'=>$claimId,'reviewer'=>$billing]);
reverseCheck((int)$reviewCount->fetchColumn()===1,
 'one immutable review audit per customer-submitted claim');
foreach ([
 "UPDATE central_vendor_payment_evidence_reviews SET reason=reason WHERE evidence_id=:id",
 "DELETE FROM central_vendor_payment_evidence_reviews WHERE evidence_id=:id",
 "UPDATE central_vendor_payment_evidence SET claimed_reference='forged' WHERE id=:id"
] as $tamper){
 try {
  $db->prepare($tamper)->execute(['id'=>$claimId]);
  throw new RuntimeException('UNEXPECTED customer evidence or review mutation');
 } catch (PDOException $expected) {
  reverseCheck($expected->getCode()==='P0001',
   'PostgreSQL protects original customer claim and immutable review audit');
 }
}
$pendingDirect=reverseId();
$db->prepare("INSERT INTO central_vendor_payment_evidence
 (id,invoice_id,claimed_reference) VALUES(:id,:invoice,'SECOND-CLAIM-UNVERIFIED')")
 ->execute(['id'=>$pendingDirect,'invoice'=>$invoice]);
try {
 $db->prepare("UPDATE central_vendor_payment_evidence SET status='reviewed' WHERE id=:id")
  ->execute(['id'=>$pendingDirect]);
 throw new RuntimeException('UNEXPECTED direct SQL review without matching audit');
} catch (PDOException $expected) {
 reverseCheck($expected->getCode()==='P0001',
  'database refuses claim status changes without immutable review event');
}
$rejected=$review->decide($billingBearer,$pendingDirect,'rejected',
 'Inspected receipt lacks independent bank settlement confirmation');
reverseCheck($rejected['decision']==='rejected' &&
 $evidence->listPending($billingBearer,$customer)===[],
 'rejected unverified evidence excluded from pending queue');
reverseCheck(!$guard->isEligible($invoice,$officer),
 'reviewed and rejected customer claims never restore reversed invoice entitlement');


$partial=$overview->forInvoice($billingBearer,$invoice);
reverseCheck(is_array($partial) && $partial['verified_minor']==='10000' &&
 $partial['reversed_minor']==='2500' && $partial['net_minor']==='7500' &&
 $partial['net_covered']===false,'safe bill-only net paid status after verified refund');
reverseCheck(array_keys($partial)===[
 'invoice_id','invoice_number','customer_id','currency','status','total_minor',
 'verified_minor','reversed_minor','net_minor','net_covered'
], 'read-only invoice overview excludes provider/payment private details');
reverseCheck($overview->forInvoice($officerBearer,$invoice)!==null,
 'license officer may read current invoice paid status without payment mutation');
reverseCheck($overview->forInvoice($foreignBearer,$invoice)===null,
 'foreign scoped billing officer cannot see another invoice');
reverseCheck($overview->forInvoice(str_repeat('0',64),$invoice)===null &&
 $overview->forInvoice($billingBearer,'not-an-invoice')===null,
 'forged session and malformed invoice are denied');
// A direct SQL insert must also serialize behind an invoice lock. This
// protects outstanding license signer eligibility against race windows.
$locked=$db->prepare('SELECT id FROM central_vendor_invoices WHERE id=:invoice FOR UPDATE');
$db->beginTransaction();
try {
 $locked->execute(['invoice'=>$invoice]);
 $locked->fetchColumn();
 $probe=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $probe->exec("SET lock_timeout TO '250ms'");
 try {
  $probe->prepare("INSERT INTO central_vendor_settlement_reversals
    (id,settlement_id,amount_minor,kind,external_reference,reason,recorded_by)
    VALUES(:id,:settlement,100,'refund','LOCK-CHECK-REFUND','Verified bank lock test',:staff)")
   ->execute(['id'=>reverseId(),'settlement'=>$settlement,'staff'=>$billing]);
  throw new RuntimeException('Direct reversal bypassed invoice lock');
 }catch(PDOException $blocked){
  reverseCheck($blocked->getCode()==='55P03',
    'direct reversal waits behind prepared invoice lock');
 }
}finally {
 if($db->inTransaction())$db->rollBack();
}
reverseCheck($service->record($billingBearer,...$args)===$first,
 'exact externally verified reference is idempotent');
reverseDeny(fn()=>$service->record($billingBearer,$settlement,2600,
 'refund','BANK-REFUND-TEST-1','Bank verified partial repayment'),
 'reversal_reference_conflict');
reverseDeny(fn()=>$service->record($billingBearer,$settlement,7501,
 'chargeback','CHARGEBACK-TEST-2','Issuer verified bank chargeback'),
 'reversal_exceeds_verified_settlement');
try {
 $db->prepare("INSERT INTO central_vendor_settlement_reversals
 (id,settlement_id,amount_minor,kind,external_reference,reason,recorded_by)
 VALUES(:id,:settlement,9000,'refund','BYPASS-OVER-REFUND','Unsafe direct over reversal',:actor)")
 ->execute(['id'=>reverseId(),'settlement'=>$settlement,'actor'=>$billing]);
 throw new RuntimeException('Direct SQL over-refund unexpectedly accepted');
} catch(PDOException $e) {
 reverseCheck($e->getCode()==='23514','database trigger rejects bypass of reversal limit');
}
$second=$service->record($billingBearer,$settlement,7500,'chargeback',
 'CHARGEBACK-TEST-2','Issuer verified bank chargeback');
reverseCheck(!$guard->isEligible($invoice,$officer),'fully reversed invoice remains unpaid');
$newSettlement=reverseId();
$insertSettlement->execute(['id'=>$newSettlement,'invoice'=>$invoice,
 'account'=>$account,'amount'=>10000,'ref'=>'synthetic-new-deposit-'.$newSettlement,
 'billing'=>$billing]);
reverseCheck($guard->isEligible($invoice,$officer),
 'separate new independently verified full settlement restores eligibility');
$covered=$overview->forInvoice($billingBearer,$invoice);
reverseCheck($covered['verified_minor']==='20000' &&
 $covered['reversed_minor']==='10000' &&
 $covered['net_minor']==='10000' && $covered['net_covered']===true,
 'invoice overview reflects a fully refunded original settlement and new independent payment');
foreach([
 "UPDATE central_vendor_settlement_reversals SET amount_minor=amount_minor WHERE id=:id",
 "DELETE FROM central_vendor_settlement_reversals WHERE id=:id"
] as $sql){
 try{
  $db->prepare($sql)->execute(['id'=>$first['id']]);
  throw new RuntimeException('UNEXPECTED reversal journal mutation');
 }catch(PDOException $e){
  reverseCheck($e->getCode()==='P0001','append-only reversal journal enforced');
 }
}
$db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
 WHERE staff_id=:id AND role='billing_officer'")->execute(['id'=>$billing]);
reverseDeny(fn()=>$service->record($billingBearer,$newSettlement,1000,
 'refund','BANK-REFUND-AFTER-REVOKE','Verified refund must be refused'),
 'staff_session_required');
reverseCheck($sessions->staffFor($billingBearer)===null,'revoked staff bearer invalidated');
reverseDeny(fn()=>$review->decide($billingBearer,$pendingDirect,'rejected',
 'Inspected receipt lacks independent bank settlement confirmation'),
 'staff_session_required');
reverseDeny(fn()=>$evidence->listPending($billingBearer,$customer),
 'billing_role_required');
echo "PASS: immutable independent vendor refund/chargeback accounting, live vendor MFA scope, exact idempotency, over-reversal guard and net license eligibility\n";
