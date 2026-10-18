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
use Hafzatech\Central\CentralStaffPasswordChangeAuditReader;
use Hafzatech\Central\StaffRolePermissions;

foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
 'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
 'CentralStaffLoginRateLimiter','CentralStaffSessionService','CentralStaffAccess',
 'CentralStaffSessionAuthorization','CentralStaffPasswordChangeAuditReader',
 'StaffRolePermissions'] as $class)
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
 (id,staff_id,role,reason) VALUES(:grant,:staff,:role,'Password audit read fixture')");
$staff=[];
foreach(['auditor','support','target'] as $name){
 $id=actionsUuid();$staff[$name]=$id;
 $account->execute(['id'=>$id,'email'=>'password-history-'.$name.'@example.test',
  'hash'=>password_hash('synthetic-password-for-fixture',PASSWORD_DEFAULT),
  'seed'=>bin2hex($seed)]);
 $role->execute(['grant'=>actionsUuid(),'staff'=>$id,
  'role'=>$name==='auditor'?'auditor':'support_agent']);
}
$insert=$db->prepare(
 "INSERT INTO central_staff_password_change_events(staff_id,outcome)
 VALUES(:staff,'changed')");
for($i=0;$i<3;$i++)$insert->execute(['staff'=>$staff['target']]);
$sessions=new CentralStaffSessionService($db,
 new CentralStaffCredentialVerifier(
  new CentralStaffPasswordVerifier($db),
  new CentralStaffMfaChallenge($db,static fn(string $bytes):string=>$bytes)),
 new CentralStaffLoginRateLimiter($db,str_repeat('w',32)));
$reader=new CentralStaffPasswordChangeAuditReader($db,
 new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db)));
$code=actionsCode($seed);
$auditorBearer=$sessions->login('password-history-auditor@example.test',
 'synthetic-password-for-fixture',$code,'192.0.2.101');
$supportBearer=$sessions->login('password-history-support@example.test',
 'synthetic-password-for-fixture',$code,'192.0.2.102');
actionsRequire(is_string($auditorBearer)&&is_string($supportBearer),
 'vendor MFA staff authentication is required');
$events=$reader->listForStaff($auditorBearer,$staff['target'],0,2);
actionsRequire(count($events)===2 &&
 $events[0]['outcome']==='changed' &&
 $events[1]['outcome']==='changed' &&
 (int)$events[0]['id']>(int)$events[1]['id'],
 'newest-first bounded password change audit page');
$older=$reader->listForStaff($auditorBearer,$staff['target'],(int)$events[1]['id'],25);
actionsRequire(count($older)===1 &&
 $older[0]['outcome']==='changed','older audit entry via cursor');
actionsRequire(array_keys($events[0])===
 ['id','staff_id','outcome','occurred_at'],
 'password change history is credential-free metadata');
foreach([
 [$supportBearer,$staff['target'],0,25],
 [str_repeat('0',64),$staff['target'],0,25],
 [$auditorBearer,'bad-id',0,25],
 [$auditorBearer,$staff['target'],-1,25],
 [$auditorBearer,$staff['target'],0,0],
 [$auditorBearer,$staff['target'],0,101]
] as [$bearer,$target,$before,$limit]){
 try{
  $reader->listForStaff($bearer,$target,$before,$limit);
  throw new RuntimeException('UNAUTHORIZED ACCOUNT AUDIT READ');
 }catch(RuntimeException $e){
  if($e->getMessage()==='UNAUTHORIZED ACCOUNT AUDIT READ')throw $e;
 }
}
$db->prepare('UPDATE central_staff_role_grants SET revoked_at=now()
 WHERE staff_id=:actor')->execute(['actor'=>$staff['auditor']]);
try{
 $reader->listForStaff($auditorBearer,$staff['target']);
 throw new RuntimeException('STALE AUDITOR WAS ACCEPTED');
}catch(RuntimeException $e){
 if($e->getMessage()==='STALE AUDITOR WAS ACCEPTED')throw $e;
}
echo "PASS: scoped immutable password change history, bounded cursor and live auditor revocation\n";
