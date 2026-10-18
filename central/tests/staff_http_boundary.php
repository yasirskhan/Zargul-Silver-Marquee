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
use Hafzatech\Central\CentralStaffCsrfTokenService;
use Hafzatech\Central\CentralStaffHttpPolicy;
use Hafzatech\Central\CentralStaffProtectedHttpBoundary;
foreach(['CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
    'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
    'CentralStaffLoginRateLimiter','CentralStaffSessionService',
    'CentralStaffAccess','CentralStaffSessionAuthorization',
    'CentralStaffCsrfTokenService','CentralStaffHttpPolicy',
    'CentralStaffProtectedHttpBoundary'] as $name)
    require dirname(__DIR__).'/src/'.$name.'.php';
function boundaryCheck(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('Vendor HTTP boundary: '.$reason);
}
function boundaryId():string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $x=bin2hex($b);
    return substr($x,0,8).'-'.substr($x,8,4).'-'.substr($x,12,4).'-'.
        substr($x,16,4).'-'.substr($x,20);
}
function boundaryTotp(string $seed):string {
    $v=intdiv(time(),30);
    $mac=hash_hmac('sha1',pack('N2',intdiv($v,4294967296),$v%4294967296),$seed,true);
    $o=ord($mac[19])&15;
    $num=unpack('N',substr($mac,$o,4))[1]&0x7fffffff;
    return str_pad((string)($num%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
boundaryCheck(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'separate central test database');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$a=boundaryId();$b=boundaryId();$actor=boundaryId();$seed='12345678901234567890';
$customer=$db->prepare('INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:label)');
$customer->execute(['id'=>$a,'label'=>'Boundary company A']);
$customer->execute(['id'=>$b,'label'=>'Boundary company B']);
$db->prepare("INSERT INTO central_staff_users
 (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
 VALUES(:id,'boundary-staff@example.test','Boundary Staff',:hash,decode(:sealed,'hex'),now(),'active')")
 ->execute(['id'=>$actor,
    'hash'=>password_hash('synthetic-boundary-password',PASSWORD_DEFAULT),
    'sealed'=>bin2hex($seed)]);
$db->prepare("INSERT INTO central_staff_role_grants
 (id,staff_id,role,customer_scope_id,reason)
 VALUES(:id,:staff,'support_agent',:customer,'Private HTTP boundary test fixture')")
 ->execute(['id'=>boundaryId(),'staff'=>$actor,'customer'=>$a]);
$sessions=new CentralStaffSessionService($db,
    new CentralStaffCredentialVerifier(
        new CentralStaffPasswordVerifier($db),
        new CentralStaffMfaChallenge($db,static fn(string $bytes):string=>$bytes)
    ),new CentralStaffLoginRateLimiter($db,str_repeat('p',32)));
$csrf=new CentralStaffCsrfTokenService($db,$sessions);
$auth=new CentralStaffSessionAuthorization($sessions,new CentralStaffAccess($db));
$off=new CentralStaffProtectedHttpBoundary($sessions,$csrf,$auth);
$on=new CentralStaffProtectedHttpBoundary($sessions,$csrf,$auth,true);
$request=[
    'HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'lichall.hafzatech.com',
    'REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/staff/session/login',
    'HTTP_ORIGIN'=>'https://lichall.hafzatech.com',
    'HTTP_SEC_FETCH_SITE'=>'same-origin','REMOTE_ADDR'=>'192.0.2.78'
];
$email='boundary-staff@example.test';$password='synthetic-boundary-password';
boundaryCheck($off->authenticate($request,$email,$password,boundaryTotp($seed))===null,
    'default disabled releases no session');
$cross=$request;$cross['HTTP_ORIGIN']='https://evil.example';
boundaryCheck($on->authenticate($cross,$email,$password,boundaryTotp($seed))===null,
    'cross-origin cannot receive vendor session');
$good=$on->authenticate($request,$email,$password,boundaryTotp($seed));
boundaryCheck(is_array($good)&&is_string($good['cookie_value']??null) &&
    is_string($good['csrf']??null),'trusted password plus TOTP issues internal response');
boundaryCheck($good['cookie_name']===CentralStaffHttpPolicy::COOKIE_NAME &&
    $good['cookie_options']['secure']===true &&
    $good['cookie_options']['httponly']===true &&
    $good['cookie_options']['samesite']==='Strict','strict staff cookie attributes');
$action=$request;$action['REQUEST_URI']='/staff/support/reply';
boundaryCheck($on->permitsMutation($action,$good['cookie_value'],$good['csrf'],
    'support.reply',$a),'valid staff bearer plus CSRF grants scoped capability');
boundaryCheck(!$on->permitsMutation($action,$good['cookie_value'],$good['csrf'],
    'support.reply',$b),'cannot cross customer boundary');
boundaryCheck(!$on->permitsMutation($action,$good['cookie_value'],$good['csrf'],
    'billing.payment.verify',$a),'cannot elevate support to finance');
boundaryCheck(!$on->permitsMutation($action,$good['cookie_value'],str_repeat('0',64),
    'support.reply',$a),'invalid CSRF denied');
boundaryCheck(!$on->permitsMutation($cross,$good['cookie_value'],$good['csrf'],
    'support.reply',$a),'invalid origin denied even with both secrets');
boundaryCheck(!$off->permitsMutation($action,$good['cookie_value'],$good['csrf'],
    'support.reply',$a),'disabled instance denies capability');
$logout=$request;$logout['REQUEST_URI']='/staff/session/logout';
$badLogout=$logout;$badLogout['HTTP_ORIGIN']='https://evil.example';
boundaryCheck(!$on->signOut($badLogout,$good['cookie_value'],$good['csrf']),
    'cross-origin logout denied');
boundaryCheck($on->signOut($logout,$good['cookie_value'],$good['csrf']),
    'authorized vendor logout succeeds');
boundaryCheck(!$on->permitsMutation($action,$good['cookie_value'],$good['csrf'],
    'support.reply',$a),'logout revokes bearer and CSRF');
echo "PASS: disabled-first central staff HTTP boundary, same-origin login, two-secret mutation, scoped authorization and protected logout\n";
