<?php
declare(strict_types=1);
use Hafzatech\Central\CentralStaffPasswordVerifier;
use Hafzatech\Central\CentralStaffTotpVerifier;
use Hafzatech\Central\CentralStaffMfaChallenge;
use Hafzatech\Central\CentralStaffCredentialVerifier;
use Hafzatech\Central\CentralStaffLoginRateLimiter;
use Hafzatech\Central\CentralStaffSessionService;
use Hafzatech\Central\CentralStaffOwnPasswordChange;
use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\CentralStaffSessionAuthorization;
use Hafzatech\Central\CentralStaffCsrfTokenService;
use Hafzatech\Central\CentralStaffHttpPolicy;
use Hafzatech\Central\CentralStaffProtectedHttpBoundary;
use Hafzatech\Central\CentralStaffPortalHttpRouter;
use Hafzatech\Central\CentralStaffPortalPrivateResponse;
use Hafzatech\Central\CentralStaffPortalOwnPassword;
use Hafzatech\Central\RegistrationError;
require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
 'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
 'CentralStaffLoginRateLimiter','CentralStaffSessionService',
 'CentralStaffOwnPasswordChange','StaffRolePermissions','CentralStaffAccess',
 'CentralStaffSessionAuthorization','CentralStaffCsrfTokenService',
 'CentralStaffHttpPolicy','CentralStaffProtectedHttpBoundary',
 'CentralStaffPortalHttpRouter','CentralStaffPortalPrivateResponse',
 'CentralStaffPortalOwnPassword',
 'CentralStaffPaymentEvidenceHttpAdapter','CentralStaffPaymentEvidenceQueue',
 'CentralStaffPaymentEvidenceReviewService','CentralStaffInvoicePaymentOverview'] as $class)
 require dirname(__DIR__).'/src/'.$class.'.php';
function ownPassCheck(bool $ok,string $reason):void{
 if(!$ok)throw new RuntimeException('Central own-password change: '.$reason);
}
function ownPassId():string{
 $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
 $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
 return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
   substr($h,16,4).'-'.substr($h,20);
}
function ownPassTotp(string $seed):string{
 $n=intdiv(time(),30);
 $h=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),$seed,true);
 $i=ord($h[19])&15;
 $v=unpack('N',substr($h,$i,4))[1]&0x7fffffff;
 return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
ownPassCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
 'separate central PostgreSQL database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seed='12345678901234567890'; // Public synthetic RFC6238 seed.
$old='synthetic-original-password-2026';
$new='synthetic-rotated-password-2026';
$user=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic Password Change',:hash,decode(:seed,'hex'),now(),'active')");
$grant=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:id,:staff,'auditor','Test current staff grants')");
$ids=[];
foreach(['self','other'] as $name){
 $id=ownPassId();$ids[$name]=$id;
 $user->execute(['id'=>$id,'email'=>'own-password-'.$name.'@example.test',
  'hash'=>password_hash($old,PASSWORD_DEFAULT),'seed'=>bin2hex($seed)]);
 $grant->execute(['id'=>ownPassId(),'staff'=>$id]);
}
$sessions=new CentralStaffSessionService($db,
 new CentralStaffCredentialVerifier(
  new CentralStaffPasswordVerifier($db),
  new CentralStaffMfaChallenge($db,static fn(string $cipher):string=>$cipher)),
 new CentralStaffLoginRateLimiter($db,str_repeat('v',32)));
$service=new CentralStaffOwnPasswordChange($db,$sessions);
$otp=ownPassTotp($seed);
$mine=$sessions->login('own-password-self@example.test',$old,$otp,'192.0.2.111');
$other=$sessions->login('own-password-other@example.test',$old,$otp,'192.0.2.112');
ownPassCheck(is_string($mine)&&is_string($other),'vendor MFA sessions established');
ownPassCheck(!$service->change(str_repeat('0',64),$old,$new),
 'forged bearer cannot rotate password');
ownPassCheck(!$service->change($mine,'incorrect-current-password',$new),
 'current password is required');
ownPassCheck(!$service->change($mine,$old,'short'),
 'weak new password cannot be used');
ownPassCheck(!$service->change($mine,$old,$old),
 'same password cannot be recycled');
ownPassCheck($service->change($mine,$old,$new),
 'active MFA staff may change OWN password with current secret');
ownPassCheck($sessions->staffFor($mine)===null,
 'own session immediately revoked after credential change');
ownPassCheck($sessions->staffFor($other)===$ids['other'],
 'another vendor staff session is not affected');
$q=$db->prepare('SELECT password_hash FROM central_staff_users WHERE id=:id');
$q->execute(['id'=>$ids['self']]);
$hash=$q->fetchColumn();
ownPassCheck(password_verify($new,$hash)&&!password_verify($old,$hash),
 'new strong hash persisted; original password invalid');
$q=$db->prepare('SELECT outcome FROM central_staff_session_events
 WHERE staff_id=:id ORDER BY id');
$q->execute(['id'=>$ids['self']]);
ownPassCheck(array_column($q->fetchAll(PDO::FETCH_ASSOC),'outcome')===
 ['issued','invalidated'],'session closure has immutable audit');
$q=$db->prepare('SELECT id,staff_id,outcome FROM central_staff_password_change_events
 WHERE staff_id=:id');
$q->execute(['id'=>$ids['self']]);
$events=$q->fetchAll(PDO::FETCH_ASSOC);
ownPassCheck(count($events)===1 && $events[0]['outcome']==='changed',
 'one durable own-password change decision without secrets');
ownPassCheck(!$service->change($mine,$new,'yet-another-strong-password'),
 'revoked session cannot mutate credentials again');
foreach([
 'UPDATE central_staff_password_change_events SET outcome=outcome WHERE id=:id',
 'DELETE FROM central_staff_password_change_events WHERE id=:id'
] as $sql){
 try{
  $db->prepare($sql)->execute(['id'=>$events[0]['id']]);
  throw new RuntimeException('UNEXPECTED PASSWORD AUDIT MUTATION');
 }catch(PDOException $e){
  if($e->getCode()!=='23514')throw $e;
 }
}
// Protected self-service password route: no target staff identifier, no
// bypass of live central session/CSRF, and password change revokes cookie.
$auth=new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db));
$csrf=new CentralStaffCsrfTokenService($db,$sessions);
$proof=$csrf->issue($other);
ownPassCheck(is_string($proof),'other vendor MFA session owns independent CSRF');
$boundary=new CentralStaffProtectedHttpBoundary($sessions,$csrf,$auth,true);
$billing=new Hafzatech\Central\CentralStaffPaymentEvidenceHttpAdapter(
 $db,$boundary,
 new Hafzatech\Central\CentralStaffPaymentEvidenceQueue($db,$auth),
 new Hafzatech\Central\CentralStaffPaymentEvidenceReviewService(
  $db,$sessions,$auth),
 new Hafzatech\Central\CentralStaffInvoicePaymentOverview(
  $db,$sessions,$auth));
$changeOff=new CentralStaffPortalOwnPassword($service);
$changeOn=new CentralStaffPortalOwnPassword($service,true);
$disabledRoute=new CentralStaffPortalHttpRouter(
 $boundary,$billing,true,null,null,null,null,null,null,null,null,$changeOff);
$protectedRoute=new CentralStaffPortalHttpRouter(
 $boundary,$billing,true,null,null,null,null,null,null,null,null,$changeOn);
$server=[
 'HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'lichall.hafzatech.com',
 'REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/staff/account/password/change',
 'HTTP_ORIGIN'=>'https://lichall.hafzatech.com',
 'HTTP_SEC_FETCH_SITE'=>'same-origin','REMOTE_ADDR'=>'192.0.2.112'
];
$cookie=[CentralStaffHttpPolicy::COOKIE_NAME=>$other];
$fields=['current_password'=>$old,'new_password'=>'synthetic-other-new-password'];
putenv('CENTRAL_STAFF_PASSWORD_HTTP_ENABLED=false');
try{
 $disabledRoute->dispatch($server,$cookie,$fields,$proof);
 throw new RuntimeException('DISABLED PASSWORD ROUTE SUCCEEDED');
}catch(RegistrationError $e){
 ownPassCheck($e->errorCode==='staff_password_http_not_enabled',
  'separate OFF service always denies password change');
}
putenv('CENTRAL_STAFF_PASSWORD_HTTP_ENABLED=true');
try{
 try{$disabledRoute->dispatch($server,$cookie,$fields,$proof);
  throw new RuntimeException('CONSTRUCTOR DISABLED PASSWORD ROUTE SUCCEEDED');
 }catch(RegistrationError $e){
  ownPassCheck($e->errorCode==='staff_password_http_not_enabled',
   'constructor release also required');
 }
 foreach([
  [$server,$cookie,$fields+['target_staff_id'=>$ids['self']],$proof],
  [$server,$cookie,$fields,str_repeat('0',64)],
  [$server,[],$fields,$proof],
  [array_replace($server,['HTTP_ORIGIN'=>'https://evil.example']),$cookie,$fields,$proof],
  [array_replace($server,['REQUEST_METHOD'=>'GET']),$cookie,$fields,$proof]
 ] as [$request,$cookies,$data,$csrfProof]){
  try{$protectedRoute->dispatch($request,$cookies,$data,$csrfProof);
   throw new RuntimeException('FORGED PASSWORD REQUEST ACCEPTED');
  }catch(RegistrationError $e){
   ownPassCheck($e->errorCode==='staff_portal_denied',
    'forged target, role, CSRF, cookie, cross-site and method denied');
  }
 }
 try{$protectedRoute->dispatch($server,$cookie,
  ['current_password'=>'wrong-current-password',
   'new_password'=>'synthetic-other-new-password'],$proof);
  throw new RuntimeException('WRONG CURRENT PASSWORD SUCCEEDED');
 }catch(RegistrationError $e){
  ownPassCheck($e->errorCode==='staff_password_change_denied',
    'old secret required for current user');
 }
 $result=$protectedRoute->dispatch($server,$cookie,$fields,$proof);
 $wire=(new CentralStaffPortalPrivateResponse(true))->compose($result);
 ownPassCheck($result['kind']==='internal_logout' &&
  $wire['cookies'][0]['name']===CentralStaffHttpPolicy::COOKIE_NAME &&
  $wire['cookies'][0]['value']==='' &&
  $wire['cookies'][0]['options']['expires']===1 &&
  !str_contains($wire['body'],$fields['current_password']) &&
  !str_contains($wire['body'],$fields['new_password']),
  'password change immediately clears strict host-only cookie');
 ownPassCheck($sessions->staffFor($other)===null,
  'password change revoked other staff bearer atomically');
 try{$protectedRoute->dispatch($server,$cookie,$fields,$proof);
  throw new RuntimeException('REUSED EXPIRED PASSWORD SESSION');}
 catch(RegistrationError $e){
  ownPassCheck($e->errorCode==='staff_portal_denied',
   'same cookie cannot modify password twice after revocation');
 }
 $q=$db->prepare('SELECT password_hash FROM central_staff_users WHERE id=:id');
 $q->execute(['id'=>$ids['other']]);
 ownPassCheck(password_verify($fields['new_password'],$q->fetchColumn()),
  'other current staff password securely rotated');
} finally {
 putenv('CENTRAL_STAFF_PASSWORD_HTTP_ENABLED=false');
}

echo "PASS: vendor own-password change requires live MFA and current secret, rejects reused/weak credentials, revokes own sessions with immutable audit\n";
