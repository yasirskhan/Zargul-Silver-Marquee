<?php
declare(strict_types=1);
use Hafzatech\Central\CentralStaffPasswordVerifier;
use Hafzatech\Central\CentralStaffTotpVerifier;
use Hafzatech\Central\CentralStaffMfaChallenge;
use Hafzatech\Central\CentralStaffCredentialVerifier;
use Hafzatech\Central\CentralStaffLoginRateLimiter;
use Hafzatech\Central\CentralStaffSessionService;
use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\CentralStaffMfaAuditReader;
use Hafzatech\Central\CentralStaffAuthenticatedMfaHistory;
use Hafzatech\Central\StaffRolePermissions;
foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
 'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
 'CentralStaffLoginRateLimiter','CentralStaffSessionService','CentralStaffAccess',
 'CentralStaffMfaAuditReader','CentralStaffAuthenticatedMfaHistory',
 'StaffRolePermissions'] as $name)
 require dirname(__DIR__).'/src/'.$name.'.php';
function mfaHistoryAssert(bool $yes,string $reason):void {
 if(!$yes)throw new RuntimeException('Vendor session-bound MFA history: '.$reason);
}
function mfaHistoryUuid():string {
 $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
 $h=bin2hex($b);
 return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
   substr($h,16,4).'-'.substr($h,20);
}
function mfaHistoryOtp(string $seed):string {
 $n=intdiv(time(),30);
 $mac=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),$seed,true);
 $offset=ord($mac[19])&15;
 $number=unpack('N',substr($mac,$offset,4))[1]&0x7fffffff;
 return str_pad((string)($number%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
mfaHistoryAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
 'isolated vendor PostgreSQL test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seed='12345678901234567890'; // Public RFC6238 seed, not a production credential.
$ins=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Session-Bound Audit Fixture',:hash,decode(:cipher,'hex'),now(),'active')");
$grant=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason) VALUES(:grant,:staff,:role,'Session audit reader regression')");
$staff=[];
foreach(['auditor','support'] as $role){
 $id=mfaHistoryUuid();$staff[$role]=$id;
 $ins->execute(['id'=>$id,'email'=>'bound-history-'.$role.'@example.test',
  'hash'=>password_hash('synthetic-audit-password',PASSWORD_DEFAULT),
  'cipher'=>bin2hex($seed)]);
 $grant->execute(['grant'=>mfaHistoryUuid(),'staff'=>$id,
  'role'=>$role==='auditor'?'auditor':'support_agent']);
}
$sessions=new CentralStaffSessionService($db,
 new CentralStaffCredentialVerifier(
  new CentralStaffPasswordVerifier($db),
  new CentralStaffMfaChallenge($db,static fn(string $cipher):string=>$cipher)),
 new CentralStaffLoginRateLimiter($db,str_repeat('h',32)));
$reader=new CentralStaffAuthenticatedMfaHistory(
 $sessions,new CentralStaffMfaAuditReader($db,new CentralStaffAccess($db)));
$code=mfaHistoryOtp($seed);
$actor=$sessions->login('bound-history-auditor@example.test',
 'synthetic-audit-password',$code,'192.0.2.91');
$support=$sessions->login('bound-history-support@example.test',
 'synthetic-audit-password',$code,'192.0.2.92');
mfaHistoryAssert(is_string($actor)&&is_string($support),'vendor MFA staff sessions issued');
$rows=$reader->listForStaff($actor,$staff['support']);
mfaHistoryAssert(count($rows)===1 && $rows[0]['outcome']==='accepted' &&
 $rows[0]['staff_id']===$staff['support'],'auditor sees intended vendor MFA outcome');
mfaHistoryAssert(array_keys($rows[0])===
 ['id','staff_id','outcome','accepted_counter','occurred_at'],
 'audit results contain allowlisted metadata, not codes or secrets');
foreach([
 [$support,$staff['auditor'],0,25],
 [str_repeat('0',64),$staff['support'],0,25],
 [$actor,'not-a-uuid',0,25],
 [$actor,$staff['support'],-1,25],
 [$actor,$staff['support'],0,101],
 [$actor,$staff['support'],0,0]
] as [$token,$subject,$cursor,$size]){
 try{
  $reader->listForStaff($token,$subject,$cursor,$size);
  throw new RuntimeException('FORBIDDEN AUDIT READ SUCCEEDED');
 }catch(RuntimeException $e){
  if($e->getMessage()==='FORBIDDEN AUDIT READ SUCCEEDED')throw $e;
 }
}
$db->prepare('UPDATE central_staff_role_grants SET revoked_at=now() WHERE staff_id=:id')
 ->execute(['id'=>$staff['auditor']]);
try{
 $reader->listForStaff($actor,$staff['support']);
 throw new RuntimeException('REVOKED AUDIT SESSION WAS ACCEPTED');
}catch(RuntimeException $e){
 if($e->getMessage()==='REVOKED AUDIT SESSION WAS ACCEPTED')throw $e;
}
echo "PASS: real central MFA session binds auditor history, denies staff UUID forgery, support role, malformed cursors and revoked access\n";
