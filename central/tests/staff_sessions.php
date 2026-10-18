<?php
declare(strict_types=1);
use Hafzatech\Central\CentralStaffPasswordVerifier;
use Hafzatech\Central\CentralStaffTotpVerifier;
use Hafzatech\Central\CentralStaffMfaChallenge;
use Hafzatech\Central\CentralStaffCredentialVerifier;
use Hafzatech\Central\CentralStaffSessionService;
use Hafzatech\Central\CentralStaffLoginRateLimiter;
use Hafzatech\Central\CentralStaffCsrfTokenService;

foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
    'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
    'CentralStaffSessionService','CentralStaffLoginRateLimiter',
    'CentralStaffCsrfTokenService'] as $name)
    require dirname(__DIR__).'/src/'.$name.'.php';

function sessCheck(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('Central session test: '.$why);
}
function sessId():string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
        substr($h,16,4).'-'.substr($h,20);
}
function sessCode(string $secret):string {
    $step=intdiv(time(),30);
    $bits=pack('N2',intdiv($step,4294967296),$step%4294967296);
    $mac=hash_hmac('sha1',$bits,$secret,true);
    $at=ord($mac[19])&15;
    $int=unpack('N',substr($mac,$at,4))[1]&0x7fffffff;
    return str_pad((string)($int%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
sessCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'separate test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$secret='12345678901234567890'; // RFC 6238 PUBLIC TEST SEED, not real secret.
$account=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic Session Test',:hash,decode(:sealed,'hex'),now(),'active')");
$role=$db->prepare("INSERT INTO central_staff_role_grants(id,staff_id,role,reason)
 VALUES(:grant,:id,'auditor','Synthetic vendor session permission')");
$ids=[];
foreach(['rotate','suspend','role','password','idle'] as $which){
    $id=sessId();$ids[$which]=$id;
    $account->execute(['id'=>$id,'email'=>'vendor-session-'.$which.'@example.test',
        'hash'=>password_hash('synthetic-vendor-pass',PASSWORD_DEFAULT),
        'sealed'=>bin2hex($secret)]);
    $role->execute(['grant'=>sessId(),'id'=>$id]);
}
$service=new CentralStaffSessionService($db,
    new CentralStaffCredentialVerifier(
        new CentralStaffPasswordVerifier($db),
        new CentralStaffMfaChallenge($db,static fn(string $sealed):string=>$sealed)
    ),
    new CentralStaffLoginRateLimiter($db,str_repeat('s',32))
);
$csrf=new CentralStaffCsrfTokenService($db,$service);
$otp=sessCode($secret);
sessCheck($service->login('vendor-session-rotate@example.test','invalid',$otp,'127.0.0.1')===null,
    'wrong password cannot mint a session');
sessCheck($service->login('venue-tenant@example.test','synthetic-vendor-pass',$otp,'127.0.0.1')===null,
    'unknown tenant identity cannot mint a session');
sessCheck($service->login('vendor-session-rotate@example.test','synthetic-vendor-pass','bad','127.0.0.1')===null,
    'malformed second factor cannot mint a session');
$q=$db->prepare('SELECT count(*) FROM central_staff_sessions WHERE staff_id=:id');
$q->execute(['id'=>$ids['rotate']]);
sessCheck((int)$q->fetchColumn()===0,'failed credentials created no session');
$token=$service->login('vendor-session-rotate@example.test','synthetic-vendor-pass',$otp,'127.0.0.1');
sessCheck(is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)===1,
    'correct two factors produce random opaque bearer');
sessCheck($service->staffFor($token)===$ids['rotate'],'session resolves vendor staff');
sessCheck($service->staffFor(str_repeat('0',64))===null,'forged token denied');
sessCheck($service->login('vendor-session-rotate@example.test','synthetic-vendor-pass',$otp,'127.0.0.1')===null,
    'TOTP step replay cannot create another session');
$q=$db->prepare("SELECT encode(token_hash,'hex') FROM central_staff_sessions WHERE staff_id=:id");
$q->execute(['id'=>$ids['rotate']]);
sessCheck($q->fetchColumn()===hash('sha256',$token),'database retains only digest');
$firstCsrf=$csrf->issue($token);
sessCheck(is_string($firstCsrf) && $csrf->accepts($token,$firstCsrf),
    'same-session independent CSRF proof accepted');
sessCheck(!$csrf->accepts($token,str_repeat('0',64)),
    'forged CSRF token denied');
sessCheck($csrf->issue(str_repeat('0',64))===null,
    'forged session cannot mint a CSRF proof');
$checkCsrf=$db->prepare("SELECT encode(csrf_hash,'hex')
 FROM central_staff_sessions WHERE staff_id=:id");
$checkCsrf->execute(['id'=>$ids['rotate']]);
sessCheck($checkCsrf->fetchColumn()===hash('sha256',$firstCsrf),
    'database stores only CSRF digest');
$replacement=$service->rotate($token);
sessCheck(!$csrf->accepts($replacement,$firstCsrf),
    'rotated session cannot reuse previous CSRF token');
$nextCsrf=$csrf->issue($replacement);
sessCheck(is_string($nextCsrf) && $nextCsrf!==$firstCsrf &&
    $csrf->accepts($replacement,$nextCsrf),
    'rotated session establishes a new CSRF proof');
sessCheck(is_string($replacement)&&$replacement!==$token,'rotation generated new bearer');
sessCheck($service->staffFor($token)===null,'rotated previous bearer fails');
sessCheck($service->staffFor($replacement)===$ids['rotate'],'new bearer is valid');
sessCheck($service->logout($replacement),'logout invalidates active bearer');
sessCheck($service->staffFor($replacement)===null,'logged-out bearer fails');
sessCheck(!$csrf->accepts($replacement,$nextCsrf),'logged-out CSRF proof denied');
$journal=$db->prepare(
    'SELECT outcome FROM central_staff_session_events WHERE staff_id=:id ORDER BY id'
);
$journal->execute(['id'=>$ids['rotate']]);
sessCheck(array_column($journal->fetchAll(PDO::FETCH_ASSOC),'outcome')===
    ['issued','rotated','logged_out'],
    'issued, rotated and logout events committed without bearer disclosure');

$other=[];
foreach(['suspend','role','password','idle'] as $which){
    $other[$which]=$service->login('vendor-session-'.$which.'@example.test',
        'synthetic-vendor-pass',$otp,'127.0.0.1');
    sessCheck(is_string($other[$which]),$which.' two-factor authenticated');
}
$db->prepare("UPDATE central_staff_users SET status='suspended' WHERE id=:id")
    ->execute(['id'=>$ids['suspend']]);
sessCheck($service->staffFor($other['suspend'])===null,'suspension invalidates session');
$db->prepare('UPDATE central_staff_role_grants SET revoked_at=now() WHERE staff_id=:id')
    ->execute(['id'=>$ids['role']]);
sessCheck($service->staffFor($other['role'])===null,'role revocation invalidates session');
$journal->execute(['id'=>$ids['role']]);
sessCheck(array_column($journal->fetchAll(PDO::FETCH_ASSOC),'outcome')===
    ['issued','invalidated'],'role revocation creates immutable invalidation event');
$db->prepare('UPDATE central_staff_users SET password_hash=:hash WHERE id=:id')
    ->execute(['id'=>$ids['password'],
        'hash'=>password_hash('reset-synthetic-password',PASSWORD_DEFAULT)]);
sessCheck($service->staffFor($other['password'])===null,'password reset invalidates session');
$db->prepare("UPDATE central_staff_sessions
 SET idle_until=created_at+interval '1 millisecond' WHERE staff_id=:id")
    ->execute(['id'=>$ids['idle']]);
sessCheck($service->staffFor($other['idle'])===null,'expired idle window denied');
sessCheck($service->staffFor('malformed')===null,'malformed bearer denied');

$eventId=$db->query('SELECT id FROM central_staff_session_events ORDER BY id LIMIT 1')
    ->fetchColumn();
sessCheck($eventId!==false,'durable staff session event retained');
foreach(['UPDATE central_staff_session_events SET outcome=outcome WHERE id=:id',
         'DELETE FROM central_staff_session_events WHERE id=:id'] as $sql){
    try{
        $db->prepare($sql)->execute(['id'=>$eventId]);
        throw new RuntimeException('Session event audit mutation unexpectedly allowed');
    }catch(PDOException $e){
        if($e->getCode()!=='23514')throw $e;
    }
}
echo "PASS: vendor MFA sessions, hashed bearer and CSRF, rotation/logout, stale role/identity denial, immutable atomic session history\n";
