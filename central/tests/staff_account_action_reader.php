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
use Hafzatech\Central\CentralStaffAccountActionReader;
use Hafzatech\Central\CentralStaffAccountDirectory;
use Hafzatech\Central\CentralStaffRoleGrantDirectory;
use Hafzatech\Central\CentralStaffHttpPolicy;
use Hafzatech\Central\CentralStaffProtectedHttpBoundary;
use Hafzatech\Central\CentralStaffCsrfTokenService;
use Hafzatech\Central\CentralStaffPortalHttpRouter;
use Hafzatech\Central\CentralStaffPortalPrivateResponse;
use Hafzatech\Central\StaffRolePermissions;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';

foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
 'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
 'CentralStaffLoginRateLimiter','CentralStaffSessionService','CentralStaffAccess',
 'CentralStaffSessionAuthorization','CentralStaffAccountActionReader',
 'StaffRolePermissions','CentralStaffAccountDirectory','CentralStaffRoleGrantDirectory',
 'CentralStaffPaymentEvidenceHttpAdapter','CentralStaffPaymentEvidenceQueue',
 'CentralStaffPaymentEvidenceReviewService','CentralStaffInvoicePaymentOverview',
 'CentralStaffHttpPolicy','CentralStaffProtectedHttpBoundary',
 'CentralStaffCsrfTokenService','CentralStaffPortalHttpRouter',
 'CentralStaffPortalPrivateResponse'] as $class)
 require dirname(__DIR__).'/src/'.$class.'.php';

function actionsRequire(bool $yes,string $reason):void {
 if(!$yes)throw new RuntimeException('Vendor account action journal: '.$reason);
}
function actionsUuid():string {
 $bytes=random_bytes(16);
 $bytes[6]=chr((ord($bytes[6])&15)|64);
 $bytes[8]=chr((ord($bytes[8])&63)|128);
 $hex=bin2hex($bytes);
 return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.
     substr($hex,16,4).'-'.substr($hex,20);
}
function actionsCode(string $seed):string {
 $n=intdiv(time(),30);
 $m=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),$seed,true);
 $o=ord($m[19])&15;
 $v=unpack('N',substr($m,$o,4))[1]&0x7fffffff;
 return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
actionsRequire(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
 'dedicated isolated PostgreSQL database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seed='12345678901234567890'; // Public RFC 6238 seed, never production.
$account=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Account Audit Fixture',:hash,decode(:seed,'hex'),now(),'active')");
$role=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:grant,:staff,:role,'Account audit read fixture')");
$staff=[];
foreach(['auditor','support','target','admin'] as $name){
 $id=actionsUuid();$staff[$name]=$id;
 $account->execute(['id'=>$id,'email'=>'action-audit-'.$name.'@example.test',
  'hash'=>password_hash('synthetic-password-for-fixture',PASSWORD_DEFAULT),
  'seed'=>bin2hex($seed)]);
 $role->execute(['grant'=>actionsUuid(),'staff'=>$id,
  'role'=>$name==='auditor'?'auditor':($name==='admin'?'staff_administrator':'support_agent')]);
}
$insert=$db->prepare("INSERT INTO central_staff_account_actions
 (actor_staff_id,target_staff_id,action,previous_status,reason)
 VALUES(:actor,:target,'suspended','active',:reason)");
foreach(['Synthetic suspension case 1','Synthetic suspension case 2',
 'Synthetic suspension case 3'] as $reason)
 $insert->execute(['actor'=>$staff['support'],'target'=>$staff['target'],
  'reason'=>$reason]);
$sessions=new CentralStaffSessionService($db,
 new CentralStaffCredentialVerifier(
  new CentralStaffPasswordVerifier($db),
  new CentralStaffMfaChallenge($db,static fn(string $bytes):string=>$bytes)),
 new CentralStaffLoginRateLimiter($db,str_repeat('w',32)));
$reader=new CentralStaffAccountActionReader($db,
 new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db)));
$code=actionsCode($seed);
$auditorBearer=$sessions->login('action-audit-auditor@example.test',
 'synthetic-password-for-fixture',$code,'192.0.2.101');
$supportBearer=$sessions->login('action-audit-support@example.test',
 'synthetic-password-for-fixture',$code,'192.0.2.102');
actionsRequire(is_string($auditorBearer)&&is_string($supportBearer),
 'vendor MFA staff authentication is required');
$adminBearer=$sessions->login('action-audit-admin@example.test',
 'synthetic-password-for-fixture',$code,'192.0.2.109');
actionsRequire(is_string($adminBearer),'distinct central Staff Admin MFA login');
$auth=new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db));
$roleReader=new CentralStaffRoleGrantDirectory($db,$auth);
$directory=new CentralStaffAccountDirectory($db,$auth);
try{$directory->listStaff($supportBearer);throw new RuntimeException('SUPPORT ENUMERATED STAFF');}
catch(RegistrationError $error){actionsRequire($error->errorCode==='staff_directory_denied','support cannot read directory');}
try{$directory->listStaff($auditorBearer);throw new RuntimeException('AUDITOR ENUMERATED STAFF');}
catch(RegistrationError $error){actionsRequire($error->errorCode==='staff_directory_denied','auditor cannot read directory');}
$accounts=$directory->listStaff($adminBearer,0,25);
actionsRequire(count($accounts)>=4 &&
 array_keys($accounts[0])===['id','email','display_name','status','mfa_enrolled','active_role_count'] &&
 !array_key_exists('password_hash',$accounts[0]) &&
 !array_key_exists('mfa_secret_ciphertext',$accounts[0]) &&
 !array_key_exists('authorization_snapshot',$accounts[0]),
 'only six allowlisted staff fields visible to current global administrator');
$adminRecord=array_values(array_filter($accounts,
 static fn(array $row)=>$row['id']===$staff['admin']));
actionsRequire(count($adminRecord)===1 &&
 $adminRecord[0]['mfa_enrolled']===true &&
 $adminRecord[0]['active_role_count']===1,
 'staff-admin account has valid MFA and one active role');
actionsRequire(count($directory->listStaff($adminBearer,1,1))===1,
 'admin directory bounded pagination');
foreach([[-1,25],[0,0],[0,26],[10001,25]] as [$offset,$limit]){
 try{$directory->listStaff($adminBearer,$offset,$limit);
 throw new RuntimeException('INVALID ADMIN PAGINATION ACCEPTED');}
 catch(RegistrationError $error){
  actionsRequire($error->errorCode==='invalid_staff_directory_request',
   'invalid offset or limit denied');
 }
}
$unsafe=CentralStaffAccountDirectory::html([[
 'display_name'=>'<script>unsafe</script>',
 'email'=>'bad<img src=x onerror=alert(1)>',
 'status'=>'active','mfa_enrolled'=>true,'active_role_count'=>1
]]);
actionsRequire(str_contains($unsafe,'&lt;script&gt;') &&
 str_contains($unsafe,'&lt;img') && !str_contains($unsafe,'<script>') &&
 !str_contains($unsafe,'<img'),
 'staff display fields are escaped in private HTML');
$csrfService=new CentralStaffCsrfTokenService($db,$sessions);
$adminCsrf=$csrfService->issue($adminBearer);
$boundary=new CentralStaffProtectedHttpBoundary($sessions,$csrfService,$auth,true);
$http=new CentralStaffPortalHttpRouter(
 $boundary, new Hafzatech\Central\CentralStaffPaymentEvidenceHttpAdapter(
  $db,$boundary,
  new Hafzatech\Central\CentralStaffPaymentEvidenceQueue($db,$auth),
  new Hafzatech\Central\CentralStaffPaymentEvidenceReviewService($db,$sessions,$auth),
  new Hafzatech\Central\CentralStaffInvoicePaymentOverview($db,$sessions,$auth)),
 true,null,null,null,null,null,$directory,$roleReader);
$server=['HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'lichall.hafzatech.com',
 'REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/staff/team/accounts',
 'HTTP_ORIGIN'=>'https://lichall.hafzatech.com',
 'HTTP_SEC_FETCH_SITE'=>'same-origin','REMOTE_ADDR'=>'192.0.2.109'];
$cookie=[CentralStaffHttpPolicy::COOKIE_NAME=>$adminBearer];
$rolesRequest=$server;$rolesRequest['REQUEST_URI']='/staff/team/roles';
$rolePage=$http->dispatch($rolesRequest,$cookie,
 ['subject_staff_id'=>$staff['support']],$adminCsrf);
actionsRequire($rolePage['kind']==='private_staff_roles_html' &&
 str_contains($rolePage['html'],'support_agent') &&
 str_contains($rolePage['html'],'Global') &&
 !str_contains($rolePage['html'],$adminBearer),
 'active support role visible to current global staff administrator');
$roleWire=(new CentralStaffPortalPrivateResponse(true))->compose($rolePage);
actionsRequire($roleWire['cookies']===[] &&
 $roleWire['headers']['Cache-Control']==='no-store, private',
 'private roles view has no session bearer or cacheable response');
try{$roleReader->listForStaff($supportBearer,$staff['support']);
 throw new RuntimeException('SUPPORT READS STAFF ROLES');}
catch(RegistrationError $error){
 actionsRequire($error->errorCode==='staff_roles_denied','support cannot view global staff grants');
}
try{$http->dispatch($rolesRequest,
 [CentralStaffHttpPolicy::COOKIE_NAME=>$supportBearer],
 ['subject_staff_id'=>$staff['support']],$adminCsrf);
 throw new RuntimeException('UNAUTHORIZED STAFF ROLES ROUTE');}
catch(RegistrationError $error){
 actionsRequire($error->errorCode==='staff_portal_denied','role route denied for nonadmin');
}
foreach([
  ['subject_staff_id'=>'invalid'],
  ['subject_staff_id'=>$staff['support'],'role'=>'super_administrator'],
  []
] as $input) {
 try{$http->dispatch($rolesRequest,$cookie,$input,$adminCsrf);
 throw new RuntimeException('FORGED ROLE QUERY');}
 catch(RegistrationError $error){
 actionsRequire($error->errorCode==='invalid_staff_roles_request',
  'role route requires only exact subject UUID');
 }
}
try{$http->dispatch($rolesRequest,$cookie,
 ['subject_staff_id'=>$staff['support']],str_repeat('0',64));
 throw new RuntimeException('FORGED ROLE CSRF');}
catch(RegistrationError $error){
 actionsRequire($error->errorCode==='staff_portal_denied',
  'role route CSRF mismatches current session');
}
$roleEscaped=CentralStaffRoleGrantDirectory::html([[
 'role'=>'<script>alert(1)</script>',
 'customer_scope_id'=>'<img src=x onerror=1>',
 'created_at'=>'2026-10-18'
]]);
actionsRequire(str_contains($roleEscaped,'&lt;script&gt;') &&
 str_contains($roleEscaped,'&lt;img') &&
 !str_contains($roleEscaped,'<script>') && !str_contains($roleEscaped,'<img'),
 'untrusted role and customer scope strings escaped before rendering');
$internal=$http->dispatch($server,$cookie,[],$adminCsrf);
actionsRequire($internal['kind']==='private_staff_directory_html' &&
 str_contains($internal['html'],'Vendor staff accounts') &&
 !str_contains($internal['html'],$adminBearer) &&
 !str_contains($internal['html'],$adminCsrf),
 'protected directory route requires real MFA staff, no secrets in HTML');
$private=(new CentralStaffPortalPrivateResponse(true))->compose($internal);
actionsRequire($private['cookies']===[] &&
 $private['headers']['Cache-Control']==='no-store, private',
 'directory page private headers and no cookie credentials');
foreach([
 [$server,$cookie,['role'=>'super_administrator'],$adminCsrf],
 [$server,$cookie,[],str_repeat('0',64)],
 [array_replace($server,['HTTP_ORIGIN'=>'https://evil.example']),$cookie,[],$adminCsrf],
 [array_replace($server,['REQUEST_METHOD'=>'GET']),$cookie,[],$adminCsrf],
] as [$srv,$cookies,$fields,$proof]){
 try{$http->dispatch($srv,$cookies,$fields,$proof);
 throw new RuntimeException('UNAUTHORIZED STAFF DIRECTORY ROUTE');}
 catch(RegistrationError $error) {
  actionsRequire(in_array($error->errorCode,
   ['invalid_staff_directory_request','staff_portal_denied'],true),
   'directory rejects extra role, CSRF, cross-site, wrong method');
 }
}
try{$http->dispatch($server,[CentralStaffHttpPolicy::COOKIE_NAME=>$supportBearer],[],$adminCsrf);
 throw new RuntimeException('SUPPORT ROUTED STAFF DIRECTORY');}
catch(RegistrationError $error){
 actionsRequire($error->errorCode==='staff_portal_denied','support route denies unauthorized role');
}

$events=$reader->listForTarget($auditorBearer,$staff['target'],0,2);
actionsRequire(count($events)===2 &&
 $events[0]['reason']==='Synthetic suspension case 3' &&
 $events[1]['reason']==='Synthetic suspension case 2',
 'newest-first bounded audit page');
$older=$reader->listForTarget($auditorBearer,$staff['target'],(int)$events[1]['id'],25);
actionsRequire(count($older)===1 &&
 $older[0]['reason']==='Synthetic suspension case 1',
 'keyset cursor loads older action');
actionsRequire(array_keys($events[0])===
 ['id','actor_staff_id','target_staff_id','action','previous_status',
 'reason','occurred_at'],
 'event view contains no bearer/MFA/password data');
foreach([
 [$supportBearer,$staff['target'],0,25],
 [str_repeat('0',64),$staff['target'],0,25],
 [$auditorBearer,'bad-id',0,25],
 [$auditorBearer,$staff['target'],-1,25],
 [$auditorBearer,$staff['target'],0,0],
 [$auditorBearer,$staff['target'],0,101]
] as [$bearer,$target,$before,$limit]){
 try{
  $reader->listForTarget($bearer,$target,$before,$limit);
  throw new RuntimeException('UNAUTHORIZED ACCOUNT AUDIT READ');
 }catch(RuntimeException $e){
  if($e->getMessage()==='UNAUTHORIZED ACCOUNT AUDIT READ')throw $e;
 }
}
$db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
 WHERE staff_id=:admin")->execute(['admin'=>$staff['admin']]);
try{$http->dispatch($server,$cookie,[],$adminCsrf);
 throw new RuntimeException('REVOKED ADMIN DIRECTORY ACCESS');}
catch(RegistrationError $error){
 actionsRequire($error->errorCode==='staff_portal_denied',
  'revoked Staff Administrator loses directory even with old session cookie');
}
$db->prepare('UPDATE central_staff_role_grants SET revoked_at=now()
 WHERE staff_id=:actor')->execute(['actor'=>$staff['auditor']]);
try{
 $reader->listForTarget($auditorBearer,$staff['target']);
 throw new RuntimeException('STALE AUDITOR WAS ACCEPTED');
}catch(RuntimeException $e){
 if($e->getMessage()==='STALE AUDITOR WAS ACCEPTED')throw $e;
}
echo "PASS: scoped live HAFZATECH MFA auditor account actions, bounded keysets, denied forged/support/stale permissions\n";
