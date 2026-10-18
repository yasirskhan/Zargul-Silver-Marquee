<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffRoleRequestService;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';
foreach([
 'StaffRolePermissions','CentralStaffAccess','CentralStaffPasswordVerifier',
 'CentralStaffTotpVerifier','CentralStaffMfaChallenge',
 'CentralStaffCredentialVerifier','CentralStaffLoginRateLimiter',
 'CentralStaffSessionService','CentralStaffRoleRequestService'
] as $class)require dirname(__DIR__).'/src/'.$class.'.php';
function rolesCheck(bool $ok,string $why):void {
 if(!$ok)throw new RuntimeException('Central role request: '.$why);
}
function rolesDeny(callable $fn,string $code):void {
 try{$fn();}catch(RegistrationError $e) {
  rolesCheck($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);
  return;
 }
 throw new RuntimeException('Central role request: missing '.$code);
}
function rolesUuid():string{
 $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
 $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
 return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
  substr($h,16,4).'-'.substr($h,20);
}
function rolesTotp(string $seed):string{
 $n=intdiv(time(),30);
 $m=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),
 $seed,true);
 $p=ord($m[19])&15;$val=unpack('N',substr($m,$p,4))[1]&0x7fffffff;
 return str_pad((string)($val%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
rolesCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
 'isolated test PostgreSQL is mandatory');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seed='12345678901234567890';$password='synthetic-role-request-password';
$staff=[];
$q=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic Role Actor',:hash,decode(:seed,'hex'),now(),:status)");
$g=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:id,:staff,:role,'Synthetic role approval fixture')");
foreach(['admin'=>'staff_administrator','support'=>'support_agent',
 'super'=>'super_administrator','target'=>null] as $name=>$role){
 $id=rolesUuid();$staff[$name]=$id;
 $q->execute(['id'=>$id,'email'=>'role-intent-'.$name.'@example.test',
  'hash'=>password_hash($password,PASSWORD_ARGON2ID),
  'seed'=>bin2hex($seed),'status'=>$name==='target'?'pending_mfa':'active']);
 if($role!==null)$g->execute(['id'=>rolesUuid(),'staff'=>$id,'role'=>$role]);
}
$db->prepare("UPDATE central_staff_users SET mfa_secret_ciphertext=NULL,
 mfa_enrolled_at=NULL WHERE id=:target")->execute(['target'=>$staff['target']]);
$sessions=new Hafzatech\Central\CentralStaffSessionService($db,
 new Hafzatech\Central\CentralStaffCredentialVerifier(
  new Hafzatech\Central\CentralStaffPasswordVerifier($db),
  new Hafzatech\Central\CentralStaffMfaChallenge(
   $db,static fn(string $cipher):string=>$cipher)),
 new Hafzatech\Central\CentralStaffLoginRateLimiter($db,str_repeat('R',32)));
$code=rolesTotp($seed);
$adminBearer=$sessions->login('role-intent-admin@example.test',
 $password,$code,'192.0.2.188');
$supportBearer=$sessions->login('role-intent-support@example.test',
 $password,$code,'192.0.2.189');
$superBearer=$sessions->login('role-intent-super@example.test',
 $password,$code,'192.0.2.190');
rolesCheck(is_string($adminBearer)&&is_string($supportBearer)&&
 is_string($superBearer),'separate vendor MFA sessions');
$access=new Hafzatech\Central\CentralStaffAccess($db);
$off=new CentralStaffRoleRequestService($db,$sessions,$access);
$on=new CentralStaffRoleRequestService($db,$sessions,$access,true);
$id=rolesUuid();
putenv('CENTRAL_STAFF_ROLE_REQUEST_ENABLED=false');
rolesDeny(fn()=>$on->request($adminBearer,$id,$staff['target'],
 'support_agent',null,'Assign support after independent approval'),
 'staff_role_requests_not_enabled');
putenv('CENTRAL_STAFF_ROLE_REQUEST_ENABLED=true');
try {
 rolesDeny(fn()=>$off->request($adminBearer,$id,$staff['target'],
  'support_agent',null,'Assign support after independent approval'),
  'staff_role_requests_not_enabled');
 rolesDeny(fn()=>$on->request($supportBearer,$id,$staff['target'],
  'support_agent',null,'Assign support after independent approval'),
  'staff_role_request_denied');
 rolesDeny(fn()=>$on->request($adminBearer,$id,$staff['admin'],
  'support_agent',null,'Self role elevation attempt'),
  'staff_role_request_denied');
 rolesDeny(fn()=>$on->request($adminBearer,'invalid',$staff['target'],
  'support_agent',null,'Malformed request identifier'),
  'invalid_staff_role_request');
 rolesDeny(fn()=>$on->request($adminBearer,$id,$staff['target'],
  'super_administrator',null,'Unauthorized top level role'),
  'staff_role_request_denied');
 $res=$on->request($adminBearer,$id,$staff['target'],
  'support_agent',null,'Assign support after independent approval');
 rolesCheck($res===['request_id'=>$id,'target_staff_id'=>$staff['target'],
  'role'=>'support_agent','state'=>'pending_independent_approval'],
  'proposed role is not active');
 rolesCheck($on->request($adminBearer,$id,$staff['target'],
  'support_agent',null,'Assign support after independent approval')===$res,
  'exact repeat is idempotent');
 rolesDeny(fn()=>$on->request($adminBearer,$id,$staff['target'],
  'billing_officer',null,'Changed after submission'),
  'staff_role_request_conflict');
 $q=$db->prepare("SELECT count(*) FROM central_staff_role_requests WHERE id=:id");
 $q->execute(['id'=>$id]);
 rolesCheck((int)$q->fetchColumn()===1,'one immutable requested role');
 $q=$db->prepare("SELECT count(*) FROM central_staff_role_grants
  WHERE staff_id=:id");
 $q->execute(['id'=>$staff['target']]);
 rolesCheck((int)$q->fetchColumn()===0,
  'pending staff role request never grants a capability');
 rolesCheck(!$access->permits($staff['target'],'support.reply',null,true),
  'pending staff with no MFA cannot use requested role');
 $high=$on->request($superBearer,rolesUuid(),$staff['target'],
  'super_administrator',null,'Independent super admin review required');
 rolesCheck($high['state']==='pending_independent_approval',
  'even super administrator request grants no immediate privilege');
 $db->prepare("INSERT INTO central_staff_role_grants
  (id,staff_id,role,reason)
  VALUES(:id,:target,'billing_officer','Synthetic segregation test')")
  ->execute(['id'=>rolesUuid(),'target'=>$staff['target']]);
 rolesDeny(fn()=>$on->request($adminBearer,rolesUuid(),$staff['target'],
  'license_officer',null,'Forbidden mixed payment and license duties'),
  'staff_role_request_denied');
 try{
  $db->prepare("DELETE FROM central_staff_role_requests WHERE id=:id")
   ->execute(['id'=>$id]);
  throw new RuntimeException('IMMUTABLE ROLE REQUEST WAS DELETED');
 }catch(PDOException $e){
  rolesCheck($e->getCode()==='23514',
   'database forbids removal of role request intent');
 }
 $db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
  WHERE staff_id=:admin")->execute(['admin'=>$staff['admin']]);
 rolesDeny(fn()=>$on->request($adminBearer,rolesUuid(),$staff['target'],
  'support_agent',null,'Revoked admin cannot request roles'),
  'staff_role_request_denied');
}finally{putenv('CENTRAL_STAFF_ROLE_REQUEST_ENABLED=false');}
echo "PASS: MFA staff role requests are pending-only, independently gated, immutable, idempotent, never immediate grants\n";
