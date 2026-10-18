<?php
declare(strict_types=1);
use Hafzatech\Central\CentralStaffPasswordVerifier;
use Hafzatech\Central\CentralStaffTotpVerifier;
use Hafzatech\Central\CentralStaffMfaChallenge;
use Hafzatech\Central\CentralStaffCredentialVerifier;
use Hafzatech\Central\CentralStaffLoginRateLimiter;
use Hafzatech\Central\CentralStaffSessionService;
use Hafzatech\Central\CentralStaffOwnPasswordChange;
foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
 'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
 'CentralStaffLoginRateLimiter','CentralStaffSessionService',
 'CentralStaffOwnPasswordChange'] as $class)
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
echo "PASS: vendor own-password change requires live MFA and current secret, rejects reused/weak credentials, revokes own sessions with immutable audit\n";
