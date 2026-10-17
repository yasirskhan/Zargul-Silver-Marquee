<?php
declare(strict_types=1);

use Hafzatech\Central\InstallationRegistry;
use Hafzatech\Central\RegistrationError;
use Hafzatech\Central\InstallationStatusService;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/InstallationStatusService.php';

function statusUuid(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $x=bin2hex($b);return substr($x,0,8).'-'.substr($x,8,4).'-'.substr($x,12,4).'-'.substr($x,16,4).'-'.substr($x,20);
}
function requireStatus(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL installation status: '.$message);
}
function statusDenied(callable $fn,string $code): void {
    try {$fn();}catch(RegistrationError $e){
        requireStatus($e->errorCode===$code,'expected '.$code.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('FAIL expected denial '.$code);
}
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn || !str_contains($dsn,'hafzatech_central_test'))
    throw new RuntimeException('Dedicated test database required');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$c=statusUuid();$other=statusUuid();$enrollment=statusUuid();$installation=statusUuid();
$pair=sodium_crypto_sign_keypair();
$stmt=$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)");
$stmt->execute(['id'=>$c,'name'=>'Synthetic signed status customer A']);
$stmt->execute(['id'=>$other,'name'=>'Synthetic signed status customer B']);
$db->prepare("INSERT INTO installation_enrollments
    (id,customer_id,token_sha256,approved_domain,domain_verified_at,expires_at,claimed_at)
    VALUES(:id,:customer,:hash,'status.example.test',now(),now()+interval '1 hour',now())")
    ->execute(['id'=>$enrollment,'customer'=>$c,'hash'=>hash('sha256',random_bytes(32))]);
$db->prepare("INSERT INTO registered_installations
    (id,customer_id,enrollment_id,approved_domain,ed25519_public_key)
    VALUES(:id,:customer,:enrollment,'status.example.test',:public)")
    ->execute(['id'=>$installation,'customer'=>$c,'enrollment'=>$enrollment,
       'public'=>InstallationRegistry::base64Url(sodium_crypto_sign_publickey($pair))]);
$service=new InstallationStatusService($db);
$sign=static function(string $customer,?string $when=null,?string $nonce=null)
    use($installation,$pair):array {
    $when=$when ?? gmdate('Y-m-d\TH:i:s\Z');
    $nonce=$nonce ?? InstallationRegistry::base64Url(random_bytes(32));
    $message=implode("\n",['HAFZATECH-INSTALL-CHECK-V1',
        $installation,$customer,$when,$nonce,'0']);
    return [
       'installation_id'=>$installation,
       'customer_id'=>$customer,
       'request_time'=>$when,
       'nonce'=>$nonce,
       'known_revision'=>0,
       'signature'=>InstallationRegistry::base64Url(sodium_crypto_sign_detached(
          $message,sodium_crypto_sign_secretkey($pair))),
    ];
};
$good=$sign($c);
$r=$service->check($good);
requireStatus($r['status']==='registered_pending_license' && $r['revision']===0,
    'No invented signed grant for newly enrolled installation');
requireStatus($r['nonce']===$good['nonce'], 'Central response acknowledges exact signed request nonce');
requireStatus($r['registered_installation_id']===$installation,
    'Authenticated customer and installation binding');
statusDenied(fn()=>$service->check($good),'replayed_check');
$foreign=$sign($other);
statusDenied(fn()=>$service->check($foreign),'unauthorized_installation');
$forged=$sign($c);$forged['signature']=InstallationRegistry::base64Url(random_bytes(64));
statusDenied(fn()=>$service->check($forged),'invalid_installation_proof');
$old=$sign($c,gmdate('Y-m-d\TH:i:s\Z',time()-3600));
statusDenied(fn()=>$service->check($old),'invalid_timestamp');
$count=$db->prepare("SELECT count(*) FROM central_installation_status_checks
    WHERE installation_id=:installation AND customer_id=:customer");
$count->execute(['installation'=>$installation,'customer'=>$c]);
requireStatus((int)$count->fetchColumn()===1,
    'Only fixture installation unique check-in recorded');
// Add 59 more synthetic distinct nonce events after the first validated
// check-in, then require the 61st authenticated request to be throttled.
$insert=$db->prepare(
    "INSERT INTO central_installation_status_checks
     (installation_id,customer_id,nonce_sha256,known_revision)
     VALUES(:installation,:customer,:nonce,0)"
);
for($n=0;$n<59;$n++){
    $insert->execute(['installation'=>$installation,'customer'=>$c,
        'nonce'=>hash('sha256',random_bytes(32))]);
}
statusDenied(fn()=>$service->check($sign($c)),'status_rate_limited');
$count=$db->prepare("SELECT count(*) FROM central_installation_status_checks
    WHERE installation_id=:installation AND customer_id=:customer");
$count->execute(['installation'=>$installation,'customer'=>$c]);
requireStatus((int)$count->fetchColumn()===60,
    'rate limit denies extra inserts for this installation and does not mutate licensing');
$db->prepare("UPDATE vendor_customers SET status='suspended' WHERE id=:id")->execute(['id'=>$c]);
statusDenied(fn()=>$service->check($sign($c)),'unauthorized_installation');
echo "PASS: signed central check-in enforces install binding, nonce replay, clock skew, cross-customer and suspension\n";
