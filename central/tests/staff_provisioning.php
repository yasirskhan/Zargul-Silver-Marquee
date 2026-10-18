<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffProvisioningService;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach([
 'StaffRolePermissions','CentralStaffAccess','CentralStaffPasswordVerifier',
 'CentralStaffTotpVerifier','CentralStaffMfaChallenge',
 'CentralStaffCredentialVerifier','CentralStaffLoginRateLimiter',
 'CentralStaffSessionService','CentralStaffProvisioningService'
] as $class)require dirname(__DIR__).'/src/'.$class.'.php';

function provisionAssert(bool $v,string $reason):void {
 if(!$v)throw new RuntimeException('Staff provisioning: '.$reason);
}
function provisionDeny(callable $fn,string $code):void {
 try{$fn();}catch(RegistrationError $e){
  provisionAssert($e->errorCode===$code,'expected '.$code.', got '.$e->errorCode);
  return;
 }
 throw new RuntimeException('Staff provisioning: missing denial '.$code);
}
function provisionId():string {
 $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
 $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
 return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
  substr($h,16,4).'-'.substr($h,20);
}
function provisionTotp(string $seed):string {
 $step=intdiv(time(),30);
 $m=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),
  $step%4294967296),$seed,true);
 $pos=ord($m[19])&15;
 $v=unpack('N',substr($m,$pos,4))[1]&0x7fffffff;
 return str_pad((string)($v%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
provisionAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
 'dedicated HAFZATECH PostgreSQL only');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seed='12345678901234567890'; // synthetic TOTP fixture only
$create=$db->prepare("INSERT INTO central_staff_users
(id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
VALUES(:id,:email,:name,:hash,decode(:secret,'hex'),now(),'active')");
$role=$db->prepare("INSERT INTO central_staff_role_grants
(id,staff_id,role,reason)
VALUES(:id,:staff,:role,'Synthetic staff provisioning authorization')");
$admin=provisionId();$support=provisionId();$password='synthetic-provisioner-password';
foreach([
 [$admin,'provision-admin@example.test','staff_administrator'],
 [$support,'provision-support@example.test','support_agent']
] as [$id,$email,$r]) {
 $create->execute(['id'=>$id,'email'=>$email,'name'=>'Synthetic User',
  'hash'=>password_hash($password,PASSWORD_ARGON2ID),
  'secret'=>bin2hex($seed)]);
 $role->execute(['id'=>provisionId(),'staff'=>$id,'role'=>$r]);
}
$sessions=new Hafzatech\Central\CentralStaffSessionService($db,
 new Hafzatech\Central\CentralStaffCredentialVerifier(
  new Hafzatech\Central\CentralStaffPasswordVerifier($db),
  new Hafzatech\Central\CentralStaffMfaChallenge(
   $db,static fn(string $secret):string=>$secret)),
 new Hafzatech\Central\CentralStaffLoginRateLimiter($db,str_repeat('p',32)));
$otp=provisionTotp($seed);
$adminBearer=$sessions->login('provision-admin@example.test',
 $password,$otp,'192.0.2.171');
$supportBearer=$sessions->login('provision-support@example.test',
 $password,$otp,'192.0.2.172');
provisionAssert(is_string($adminBearer)&&is_string($supportBearer),
 'two separate current MFA sessions');
$off=new CentralStaffProvisioningService(
 $db,$sessions,new Hafzatech\Central\CentralStaffAccess($db));
$on=new CentralStaffProvisioningService(
 $db,$sessions,new Hafzatech\Central\CentralStaffAccess($db),true);
putenv('CENTRAL_STAFF_PROVISIONING_ENABLED=false');
provisionDeny(fn()=>$on->createPending($adminBearer,
 'new-vendor@example.test','New Support User','Approved initial staffing'),
 'staff_provisioning_not_enabled');
putenv('CENTRAL_STAFF_PROVISIONING_ENABLED=true');
try{
 provisionDeny(fn()=>$off->createPending($adminBearer,
  'new-vendor@example.test','New Support User','Approved initial staffing'),
  'staff_provisioning_not_enabled');
 provisionDeny(fn()=>$on->createPending($supportBearer,
  'new-vendor@example.test','New Support User','Approved initial staffing'),
  'staff_provision_denied');
 provisionDeny(fn()=>$on->createPending(str_repeat('0',64),
  'new-vendor@example.test','New Support User','Approved initial staffing'),
  'staff_provision_denied');
 provisionDeny(fn()=>$on->createPending($adminBearer,
  'not-a-valid-email','New User','Approved initial staffing'),
  'invalid_staff_provision_request');
 provisionDeny(fn()=>$on->createPending($adminBearer,
  'new-vendor@example.test','x','Approved initial staffing'),
  'invalid_staff_provision_request');
 $created=$on->createPending($adminBearer,
  ' New-Vendor@Example.Test ','New Support User','Approved initial staffing');
 provisionAssert(array_keys($created)===
  ['staff_id','email','status','activation_ready'] &&
  $created['email']==='new-vendor@example.test' &&
  $created['status']==='pending_mfa' &&
  $created['activation_ready']===false &&
  preg_match('/^[a-f0-9-]{36}$/D',$created['staff_id'])===1,
  'server creates only opaque pending unactivated staff identity');
 provisionAssert($on->createPending($adminBearer,
  'new-vendor@example.test','New Support User','Approved initial staffing')===$created,
  'same authorized issuer exact retry returns one durable provisional account');
 $q=$db->prepare("SELECT status,mfa_enrolled_at,mfa_secret_ciphertext,
  password_hash FROM central_staff_users WHERE id=:id");
 $q->execute(['id'=>$created['staff_id']]);$state=$q->fetch(PDO::FETCH_ASSOC);
 provisionAssert($state['status']==='pending_mfa' &&
  $state['mfa_enrolled_at']===null &&
  $state['mfa_secret_ciphertext']===null &&
  !password_verify('guessable-password',$state['password_hash']),
  'provisional account has no MFA enrollment or known password');
 $q=$db->prepare("SELECT count(*) FROM central_staff_role_grants WHERE staff_id=:id");
 $q->execute(['id'=>$created['staff_id']]);
 provisionAssert((int)$q->fetchColumn()===0,'no staff roles automatically assigned');
 provisionAssert($sessions->login('new-vendor@example.test','guessable-password',
  $otp,'192.0.2.173')===null,
  'provisional unassigned account cannot establish a session');
 $q=$db->prepare("SELECT id,actor_staff_id,action,reason
  FROM central_staff_provision_events WHERE target_staff_id=:target");
 $q->execute(['target'=>$created['staff_id']]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
 provisionAssert(count($events)===1 && $events[0]['actor_staff_id']===$admin &&
  $events[0]['action']==='created_pending_mfa' &&
  $events[0]['reason']==='Approved initial staffing',
  'one append-only actor-attributed account provision decision');
 provisionDeny(fn()=>$on->createPending($adminBearer,
  'new-vendor@example.test','Different display name','Approved initial staffing'),
  'staff_account_already_exists');
 provisionDeny(fn()=>$on->createPending($adminBearer,
  'new-vendor@example.test','Changed Staff','Different reason'),
  'staff_account_already_exists');
 try{$db->prepare("UPDATE central_staff_provision_events SET reason=reason
   WHERE id=:event")->execute(['event'=>$events[0]['id']]);
  throw new RuntimeException('UNEXPECTED PROVISION JOURNAL EDIT');}
 catch(PDOException $e){
  provisionAssert($e->getCode()==='23514',
   'database protects account provisioning journal');
 }
 $db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
  WHERE staff_id=:actor")->execute(['actor'=>$admin]);
 provisionDeny(fn()=>$on->createPending($adminBearer,
  'second-vendor@example.test','Second User','Approved initial staffing'),
  'staff_provision_denied');
}finally{putenv('CENTRAL_STAFF_PROVISIONING_ENABLED=false');}
echo "PASS: central MFA staff creates pending-only account, no automatic roles or credentials, immutable audit and idempotent protected retries\n";
