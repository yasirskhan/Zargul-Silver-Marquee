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
use Hafzatech\Central\StaffRolePermissions;

foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
    'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
    'CentralStaffLoginRateLimiter','CentralStaffSessionService',
    'CentralStaffAccess','CentralStaffSessionAuthorization',
    'CentralStaffSessionAuditReader','StaffRolePermissions'] as $class) {
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
$db->prepare('UPDATE central_staff_role_grants SET revoked_at=now()
 WHERE staff_id=:id')->execute(['id'=>$staff['auditor']]);
try{
    $reader->listForStaff($auditorBearer,$staff['support']);
    throw new RuntimeException('REVOKED AUDITOR SESSION STILL AUTHORIZED');
}catch(RuntimeException $error){
    if($error->getMessage()==='REVOKED AUDITOR SESSION STILL AUTHORIZED')
        throw $error;
}
echo "PASS: live MFA staff session required, bounded vendor audit cursor and metadata, revoked/foreign role denial\n";
