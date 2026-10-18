<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffPasswordVerifier;
use Hafzatech\Central\CentralStaffTotpVerifier;
use Hafzatech\Central\CentralStaffMfaChallenge;
use Hafzatech\Central\CentralStaffCredentialVerifier;
use Hafzatech\Central\CentralStaffLoginRateLimiter;
use Hafzatech\Central\CentralStaffSessionService;
use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\CentralStaffSessionAuthorization;
use Hafzatech\Central\CentralStaffSessionAuditReader;
use Hafzatech\Central\CentralStaffAccountActionReader;
use Hafzatech\Central\CentralStaffPortalAuditHistoryPage;
use Hafzatech\Central\StaffRolePermissions;

require dirname(__DIR__).'/src/InstallationRegistry.php';

foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
    'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
    'CentralStaffLoginRateLimiter','CentralStaffSessionService',
    'CentralStaffAccess','CentralStaffSessionAuthorization',
    'CentralStaffSessionAuditReader','CentralStaffAccountActionReader',
    'CentralStaffPortalAuditHistoryPage','StaffRolePermissions',
    'CentralStaffCsrfTokenService','CentralStaffHttpPolicy',
    'CentralStaffProtectedHttpBoundary','CentralStaffPaymentEvidenceQueue',
    'CentralStaffPaymentEvidenceReviewService',
    'CentralStaffInvoicePaymentOverview','CentralStaffPaymentEvidenceHttpAdapter',
    'CentralStaffPortalHttpRouter','CentralStaffPortalPrivateResponse',
    'CentralStaffPortalAuditorDashboard'] as $class) {
    require dirname(__DIR__).'/src/'.$class.'.php';
}
function journalRequire(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('Central session audit reader: '.$message);
}
function journalUuid():string {
    $b=random_bytes(16);
    $b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
        substr($h,16,4).'-'.substr($h,20);
}
function journalTotp(string $seed):string {
    $counter=intdiv(time(),30);
    $mac=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),
        $counter%4294967296),$seed,true);
    $off=ord($mac[19])&15;
    $value=unpack('N',substr($mac,$off,4))[1]&0x7fffffff;
    return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
journalRequire(is_string($dsn) && str_contains($dsn,'hafzatech_central_test'),
    'isolated central database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seed='12345678901234567890'; // Public test vector, never production.
$accounts=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic Session History',:hash,decode(:sealed,'hex'),now(),'active')");
$grants=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason)
 VALUES(:grant,:staff,:role,'Synthetic session audit read scope')");
$staff=[];
foreach(['auditor','support'] as $role){
    $id=journalUuid(); $email='session-history-'.$role.'@example.test';
    $accounts->execute(['id'=>$id,'email'=>$email,
        'hash'=>password_hash('synthetic-history-password',PASSWORD_DEFAULT),
        'sealed'=>bin2hex($seed)]);
    $grants->execute(['grant'=>journalUuid(),'staff'=>$id,
        'role'=>$role==='auditor'?'auditor':'support_agent']);
    $staff[$role]=$id;
}
$sessions=new CentralStaffSessionService($db,
    new CentralStaffCredentialVerifier(
        new CentralStaffPasswordVerifier($db),
        new CentralStaffMfaChallenge($db,static fn(string $bytes):string=>$bytes)
    ),new CentralStaffLoginRateLimiter($db,str_repeat('j',32)));
$reader=new CentralStaffSessionAuditReader($db,
    new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db)));
$otp=journalTotp($seed);
$auditorBearer=$sessions->login('session-history-auditor@example.test',
    'synthetic-history-password',$otp,'192.0.2.31');
$supportBearer=$sessions->login('session-history-support@example.test',
    'synthetic-history-password',$otp,'192.0.2.32');
journalRequire(is_string($auditorBearer) && is_string($supportBearer),
    'isolated staff two-factor session must be accepted');
$rotated=$sessions->rotate($supportBearer);
journalRequire(is_string($rotated),'vendor session rotation generated a bearer');
try{
    $reader->listForStaff($rotated,$staff['auditor']);
    throw new RuntimeException('SUPPORT ROLE READ SECURITY AUDIT');
}catch(RuntimeException $error){
    if($error->getMessage()==='SUPPORT ROLE READ SECURITY AUDIT')throw $error;
}
journalRequire($sessions->logout($rotated),
    'support session logout creates immutable event');
$events=$reader->listForStaff($auditorBearer,$staff['support'],0,2);
journalRequire(count($events)===2 &&
    array_column($events,'outcome')===['logged_out','rotated'],
    'bounded newest-first cursor includes only requested vendor staff');
$older=$reader->listForStaff($auditorBearer,$staff['support'],
    (int)$events[1]['id'],25);
journalRequire(count($older)===1 && $older[0]['outcome']==='issued',
    'keyset pagination returns older session issue');
journalRequire($events[0]['staff_id']===$staff['support'] &&
    $events[0]['session_id']===$events[1]['session_id'],
    'events refer to same vendor staff session without storing secrets');
foreach($events as $event){
    journalRequire(array_keys($event)===
        ['id','staff_id','session_id','outcome','created_at'],
        'audit event response allowlists only safe metadata');
}
foreach([
    [$supportBearer,$staff['auditor'],0,25],
    [$auditorBearer,'not-a-uuid',0,25],
    [$auditorBearer,$staff['support'],-1,25],
    [$auditorBearer,$staff['support'],0,101],
    [$auditorBearer,$staff['support'],0,0],
    [str_repeat('f',64),$staff['support'],0,25]
] as [$bearer,$subject,$cursor,$limit]){
    try{
        $reader->listForStaff($bearer,$subject,$cursor,$limit);
        throw new RuntimeException('FORBIDDEN AUDIT READ SUCCEEDED');
    }catch(RuntimeException $error){
        if($error->getMessage()==='FORBIDDEN AUDIT READ SUCCEEDED')throw $error;
    }
}
// The protected audit page uses TWO independently authorized immutable
// readers and omits session-id, account reason and all bearer/MFA values.
$db->prepare("INSERT INTO central_staff_account_actions
 (actor_staff_id,target_staff_id,action,previous_status,reason)
 VALUES(:actor,:target,'suspended','active',:reason)")
 ->execute(['actor'=>$staff['auditor'],'target'=>$staff['support'],
    'reason'=>'Synthetic internal note NEVER EXPOSE AUDIT REASON']);
$auditPage=new CentralStaffPortalAuditHistoryPage(
 $reader,new CentralStaffAccountActionReader(
  $db,new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db))));
$privateAudit=$auditPage->render($auditorBearer,$staff['support']);
journalRequire(str_contains($privateAudit,'Staff security history') &&
 str_contains($privateAudit,'logged_out') &&
 str_contains($privateAudit,'rotated') &&
 str_contains($privateAudit,'suspended') &&
 !str_contains($privateAudit,'NEVER EXPOSE AUDIT REASON') &&
 !str_contains($privateAudit,$auditorBearer) &&
 !str_contains($privateAudit,$staff['support']) &&
 !str_contains($privateAudit,$events[0]['session_id']),
 'MFA auditor gets minimal immutable history and no private identifiers');
try{
 $auditPage->render($rotated,$staff['support']);
 throw new RuntimeException('UNAUTHORIZED SUPPORT AUDIT PAGE');
}catch(RuntimeException $error){
 if($error->getMessage()==='UNAUTHORIZED SUPPORT AUDIT PAGE')throw $error;
}
// Internal audit endpoint stays detached from public routing and requires
// exact HTTPS origin POST, live MFA audit.read + session-bound CSRF. No
// production operator deployment or separate signing/payment privileges.
$auditAuth=new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db));
$auditCsrf=new \Hafzatech\Central\CentralStaffCsrfTokenService($db,$sessions);
$proof=$auditCsrf->issue($auditorBearer);
journalRequire(is_string($proof),'auditor receives independent session CSRF');
$boundary=new \Hafzatech\Central\CentralStaffProtectedHttpBoundary(
    $sessions,$auditCsrf,$auditAuth,true);
$billingDisabled=new \Hafzatech\Central\CentralStaffPaymentEvidenceHttpAdapter(
    $db,$boundary,
    new \Hafzatech\Central\CentralStaffPaymentEvidenceQueue($db,$auditAuth),
    new \Hafzatech\Central\CentralStaffPaymentEvidenceReviewService(
        $db,$sessions,$auditAuth),
    new \Hafzatech\Central\CentralStaffInvoicePaymentOverview(
        $db,$sessions,$auditAuth));
$routerOff=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
    $boundary,$billingDisabled);
$routerOn=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
    $boundary,$billingDisabled,true,null,null,null,null,$auditPage);
$staffCookie=[
    \Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME=>$auditorBearer
];
$staffAuditRequest=[
    'HTTPS'=>'on','SERVER_PORT'=>'443',
    'HTTP_HOST'=>'lichall.hafzatech.com','REQUEST_METHOD'=>'POST',
    'REQUEST_URI'=>'/staff/audit/staff',
    'HTTP_ORIGIN'=>'https://lichall.hafzatech.com',
    'HTTP_SEC_FETCH_SITE'=>'same-origin','REMOTE_ADDR'=>'192.0.2.116'
];
$staffAuditFields=['subject_staff_id'=>$staff['support']];
try {
 $routerOff->dispatch($staffAuditRequest,$staffCookie,$staffAuditFields,$proof);
 throw new RuntimeException('DISABLED STAFF AUDIT ROUTE EXPOSED');
} catch (\Hafzatech\Central\RegistrationError $error) {
 journalRequire($error->errorCode==='staff_portal_not_enabled',
  'default disabled staff audit route');
}
$auditorHome=new \Hafzatech\Central\CentralStaffPortalAuditorDashboard(
    $auditAuth);
$auditorRouter=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
    $boundary,$billingDisabled,true,null,null,null,null,$auditPage,
    null,null,null,null,null,null,$auditorHome);
$homeRequest=$staffAuditRequest;
$homeRequest['REQUEST_URI']='/staff/audit/dashboard';
$homePage=$auditorRouter->dispatch($homeRequest,$staffCookie,[],$proof);
journalRequire($homePage['kind']==='private_auditor_home_html' &&
    str_contains($homePage['html'],'action="/staff/audit/staff"') &&
    str_contains($homePage['html'],'name="subject_staff_id"') &&
    str_contains($homePage['html'],'action="/staff/session/logout"') &&
    !str_contains($homePage['html'],'action="/staff/team/accounts"') &&
    !str_contains($homePage['html'],'/staff/billing/overview') &&
    !str_contains($homePage['html'],$auditorBearer),
    'current MFA Auditor only sees restricted staff audit navigation');
$homeResponse=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
    ->compose($homePage);
journalRequire($homeResponse['cookies']===[] &&
    $homeResponse['headers']['Cache-Control']==='no-store, private',
    'staff Auditor page remains protected and cookie free');
foreach([
 [$homeRequest,$staffCookie,['role'=>'super_administrator'],$proof],
 [$homeRequest,$staffCookie,[],str_repeat('0',64)],
 [array_replace($homeRequest,['HTTP_ORIGIN'=>'https://evil.example']),
  $staffCookie,[],$proof],
 [array_replace($homeRequest,['REQUEST_METHOD'=>'GET']),
  $staffCookie,[],$proof]
] as [$srv,$cookies,$body,$csrfToken]) {
 try{$auditorRouter->dispatch($srv,$cookies,$body,$csrfToken);
  throw new RuntimeException('UNAUTHORIZED AUDITOR HOME ACCESS');}
 catch(\Hafzatech\Central\RegistrationError $e){
  journalRequire(in_array($e->errorCode,
    ['invalid_staff_auditor_dashboard','staff_portal_denied'],true),
    'auditor dashboard refuses forged role, origin, CSRF and GET');
 }
}
$auditorPage=$routerOn->dispatch(
    $staffAuditRequest,$staffCookie,$staffAuditFields,$proof);
journalRequire($auditorPage['kind']==='private_audit_html' &&
    str_contains($auditorPage['html'],'logged_out') &&
    !str_contains($auditorPage['html'],$auditorBearer) &&
    !str_contains($auditorPage['html'],$staff['support']),
    'protected security audit page has no bearer or subject identity');
$privateOutput=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
    ->compose($auditorPage);
journalRequire($privateOutput['status']===200 &&
    $privateOutput['cookies']===[] &&
    $privateOutput['headers']['Cache-Control']==='no-store, private',
    'auditor HTML output is private no-store and has no cookie');
foreach([
    [$staffAuditRequest,$staffCookie,[
        'subject_staff_id'=>'invalid'],$proof],
    [$staffAuditRequest,$staffCookie,[
        'subject_staff_id'=>$staff['support'],'other'=>'extra'],$proof],
    [$staffAuditRequest,$staffCookie,$staffAuditFields,str_repeat('0',64)],
    [$staffAuditRequest,[],$staffAuditFields,$proof],
    [array_replace($staffAuditRequest,['HTTP_ORIGIN'=>'https://attacker.example']),
        $staffCookie,$staffAuditFields,$proof],
    [array_replace($staffAuditRequest,['REQUEST_METHOD'=>'GET']),
        $staffCookie,$staffAuditFields,$proof]
] as [$srv,$cookie,$fields,$token]) {
 try {
  $routerOn->dispatch($srv,$cookie,$fields,$token);
  throw new RuntimeException('UNSAFE STAFF AUDIT REQUEST ACCEPTED');
 } catch(\Hafzatech\Central\RegistrationError $error) {
  journalRequire(in_array($error->errorCode,[
    'invalid_staff_audit_request','staff_portal_denied'],true),
    'invalid audit form/origin/cookie or proof refused');
 }
}
$db->prepare('UPDATE central_staff_role_grants SET revoked_at=now()
 WHERE staff_id=:id')->execute(['id'=>$staff['auditor']]);
try{
    $reader->listForStaff($auditorBearer,$staff['support']);
    throw new RuntimeException('REVOKED AUDITOR SESSION STILL AUTHORIZED');
}catch(RuntimeException $error){
    if($error->getMessage()==='REVOKED AUDITOR SESSION STILL AUTHORIZED')
        throw $error;
}
try{
 $auditPage->render($auditorBearer,$staff['support']);
 throw new RuntimeException('REVOKED AUDITOR PAGE ACCESS');
}catch(RuntimeException $error){
 if($error->getMessage()==='REVOKED AUDITOR PAGE ACCESS')throw $error;
}
try{
 $auditorRouter->dispatch($homeRequest,$staffCookie,[],$proof);
 throw new RuntimeException('REVOKED AUDITOR HOME ACCESS');
}catch(\Hafzatech\Central\RegistrationError $e){
 journalRequire($e->errorCode==='staff_portal_denied',
  'revoked auditor loses private role homepage');
}
try {
 $routerOn->dispatch($staffAuditRequest,$staffCookie,$staffAuditFields,$proof);
 throw new RuntimeException('REVOKED AUDITOR ROUTE ACCESS');
} catch (\Hafzatech\Central\RegistrationError $error) {
 journalRequire($error->errorCode==='staff_portal_denied',
   'revoked audit session cannot reuse private route');
}
echo "PASS: live MFA staff session required, bounded vendor audit cursor and metadata, revoked/foreign role denial\n";
