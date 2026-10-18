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
use Hafzatech\Central\CentralStaffSuspensionService;
use Hafzatech\Central\StaffRolePermissions;
foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
    'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
    'CentralStaffLoginRateLimiter','CentralStaffSessionService',
    'CentralStaffAccess','CentralStaffSessionAuthorization',
    'CentralStaffSuspensionService','StaffRolePermissions'] as $name)
    require dirname(__DIR__).'/src/'.$name.'.php';
function suspendCheck(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException('Central staff suspension: '.$why);
}
function suspendUuid():string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
        substr($h,16,4).'-'.substr($h,20);
}
function suspendTotp(string $seed):string {
    $counter=intdiv(time(),30);
    $hash=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),
        $counter%4294967296),$seed,true);
    $offset=ord($hash[19])&15;
    $integer=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;
    return str_pad((string)($integer%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
suspendCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'independent central PostgreSQL test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$seed='12345678901234567890'; // Public RFC 6238 test seed.
$insert=$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,:email,'Synthetic Vendor Suspension',:hash,decode(:sealed,'hex'),now(),'active')");
$grant=$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,reason)
 VALUES(:grant,:staff,:role,'Synthetic vendor account safety test')");
$staff=[];
foreach(['admin'=>'staff_administrator','target'=>'support_agent',
         'super'=>'super_administrator','support'=>'support_agent'] as $key=>$role){
    $id=suspendUuid();$staff[$key]=$id;
    $insert->execute(['id'=>$id,'email'=>'suspension-'.$key.'@example.test',
        'hash'=>password_hash('synthetic-vendor-password',PASSWORD_DEFAULT),
        'sealed'=>bin2hex($seed)]);
    $grant->execute(['grant'=>suspendUuid(),'staff'=>$id,'role'=>$role]);
}
$sessions=new CentralStaffSessionService($db,
    new CentralStaffCredentialVerifier(
        new CentralStaffPasswordVerifier($db),
        new CentralStaffMfaChallenge($db,static fn(string $cipher):string=>$cipher)
    ),new CentralStaffLoginRateLimiter($db,str_repeat('k',32)));
$authorization=new CentralStaffSessionAuthorization(
    $sessions,new CentralStaffAccess($db));
$service=new CentralStaffSuspensionService($db,$sessions,$authorization);
$otp=suspendTotp($seed);
$admin=$sessions->login('suspension-admin@example.test',
    'synthetic-vendor-password',$otp,'192.0.2.81');
$target=$sessions->login('suspension-target@example.test',
    'synthetic-vendor-password',$otp,'192.0.2.82');
$support=$sessions->login('suspension-support@example.test',
    'synthetic-vendor-password',$otp,'192.0.2.83');
suspendCheck(is_string($admin)&&is_string($target)&&is_string($support),
    'fresh central passwords and TOTP establish independent accounts');
suspendCheck(!$service->suspend($target,$staff['admin'],'Invalid request'),
    'ordinary support account cannot suspend administrator');
suspendCheck(!$service->suspend($admin,$staff['admin'],'Self suspension'),
    'administrator cannot suspend own account');
suspendCheck(!$service->suspend($admin,$staff['super'],'Privileged restriction'),
    'Super Administrator cannot be suspended via ordinary staff action');
suspendCheck(!$service->suspend(str_repeat('0',64),$staff['target'],'Forged session'),
    'forged bearer cannot suspend staff');
suspendCheck(!$service->suspend($admin,$staff['target'],'bad'),
    'short reason rejected without mutation');
suspendCheck(!$service->suspend($admin,'not-a-uuid','Malformed subject'),
    'invalid staff identifier rejected');
suspendCheck($service->suspend($admin,$staff['target'],
    'Owner-approved security response test'),'authorized second-party suspension');
suspendCheck($sessions->staffFor($target)===null,
    'existing target session cannot authenticate after suspension');
$q=$db->prepare('SELECT status FROM central_staff_users WHERE id=:id');
$q->execute(['id'=>$staff['target']]);
suspendCheck($q->fetchColumn()==='suspended','target status persisted');
$q=$db->prepare('SELECT action,previous_status,actor_staff_id,target_staff_id,reason
 FROM central_staff_account_actions WHERE target_staff_id=:id');
$q->execute(['id'=>$staff['target']]);
$events=$q->fetchAll(PDO::FETCH_ASSOC);
suspendCheck(count($events)===1 && $events[0]['action']==='suspended' &&
    $events[0]['actor_staff_id']===$staff['admin'] &&
    $events[0]['previous_status']==='active' &&
    $events[0]['reason']==='Owner-approved security response test',
    'actor, target, reason and original status audit durable');
$q=$db->prepare('SELECT outcome FROM central_staff_session_events WHERE staff_id=:id
 ORDER BY id');
$q->execute(['id'=>$staff['target']]);
suspendCheck(array_column($q->fetchAll(PDO::FETCH_ASSOC),'outcome')===
    ['issued','invalidated'],'target sessions revoked with immutable audit');
suspendCheck(!$service->suspend($admin,$staff['target'],'Repeated suspension'),
    'repeated suspension cannot create duplicate event');
$q=$db->prepare('SELECT count(*) FROM central_staff_account_actions
 WHERE target_staff_id=:id');
$q->execute(['id'=>$staff['target']]);
suspendCheck((int)$q->fetchColumn()===1,'idempotent denial retains single record');
$first=$db->query('SELECT id FROM central_staff_account_actions ORDER BY id LIMIT 1')
 ->fetchColumn();
foreach(['DELETE FROM central_staff_account_actions WHERE id=:id',
         'UPDATE central_staff_account_actions SET reason=reason WHERE id=:id'] as $sql){
    try{
        $db->prepare($sql)->execute(['id'=>$first]);
        throw new RuntimeException('STAFF AUDIT WAS MUTATED');
    }catch(PDOException $e){
        if($e->getCode()!=='23514')throw $e;
    }
}
suspendCheck($sessions->staffFor($admin)===$staff['admin'],
    'separate acting administrator session unaffected');
echo "PASS: vendor MFA administrator scoped suspension, self/super/foreign/forged denial, atomic revocation and immutable audit\n";
