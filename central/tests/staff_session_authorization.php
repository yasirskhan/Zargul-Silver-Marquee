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
use Hafzatech\Central\StaffRolePermissions;

foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
    'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
    'CentralStaffLoginRateLimiter','CentralStaffSessionService',
    'CentralStaffAccess','CentralStaffSessionAuthorization',
    'StaffRolePermissions'] as $name)
    require dirname(__DIR__).'/src/'.$name.'.php';
function scopedCheck(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException('Session authorization: '.$why);
}
function scopedUuid():string{
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
        substr($h,16,4).'-'.substr($h,20);
}
function scopedTotp(string $secret):string {
    $count=intdiv(time(),30);
    $bytes=pack('N2',intdiv($count,4294967296),$count%4294967296);
    $digest=hash_hmac('sha1',$bytes,$secret,true);
    $index=ord($digest[19])&15;
    $num=unpack('N',substr($digest,$index,4))[1]&0x7fffffff;
    return str_pad((string)($num%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
scopedCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'central isolated database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$a=scopedUuid();$b=scopedUuid();$actor=scopedUuid();$grant=scopedUuid();
$c=$db->prepare('INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:label)');
$c->execute(['id'=>$a,'label'=>'Synthetic authorized support company']);
$c->execute(['id'=>$b,'label'=>'Synthetic foreign support company']);
$seed='12345678901234567890';
$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,'scoped-vendor-session@example.test','Scoped MFA Agent',
 :password,decode(:sealed,'hex'),now(),'active')")
 ->execute(['id'=>$actor,
    'password'=>password_hash('synthetic-authorized-pass',PASSWORD_DEFAULT),
    'sealed'=>bin2hex($seed)]);
$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,customer_scope_id,reason)
 VALUES(:grant,:staff,'support_agent',:customer,'Synthetic vendor scoped session grant')")
 ->execute(['grant'=>$grant,'staff'=>$actor,'customer'=>$a]);
$sessions=new CentralStaffSessionService($db,
    new CentralStaffCredentialVerifier(
        new CentralStaffPasswordVerifier($db),
        new CentralStaffMfaChallenge($db,static fn(string $sealed):string=>$sealed)
    ),new CentralStaffLoginRateLimiter($db,str_repeat('m',32)));
$auth=new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db));
scopedCheck(!$auth->permits($actor,'support.reply',$a),
    'raw staff identifier cannot pretend to be MFA session');
scopedCheck(!$auth->permits(str_repeat('0',64),'support.reply',$a),
    'forged bearer denied');
scopedCheck($sessions->login('scoped-vendor-session@example.test','wrong',
    scopedTotp($seed),'192.0.2.27')===null,'bad password cannot mint bearer');
$token=$sessions->login('scoped-vendor-session@example.test',
    'synthetic-authorized-pass',scopedTotp($seed),'192.0.2.27');
scopedCheck(is_string($token),'verified password and TOTP required');
scopedCheck($auth->permits($token,'support.reply',$a),
    'live scoped MFA staff can reply for assigned customer');
scopedCheck(!$auth->permits($token,'support.reply',$b),
    'foreign customer denied');
scopedCheck(!$auth->permits($token,'billing.payment.verify',$a),
    'vendor support role cannot verify money');
$db->prepare("UPDATE central_staff_role_grants SET revoked_at=now() WHERE id=:id")
 ->execute(['id'=>$grant]);
scopedCheck(!$auth->permits($token,'support.reply',$a),
    'role revocation denies already issued session on next request');
echo "PASS: vendor real session identity, live scoped capabilities, denied forged staff MFA, finance and foreign customer\n";
