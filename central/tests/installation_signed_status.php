<?php
declare(strict_types=1);

use Hafzatech\Central\InstallationRegistry;
use Hafzatech\Central\InstallationStatusService;
use Hafzatech\Central\CentralInstallationSignedStatusResponse;
use Hafzatech\Central\SignedGrantDeliveryService;
use Hafzatech\Central\RegistrationError;
use Hafzatech\Signing\Ed25519StatusReceiptCodec;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/InstallationStatusService.php';
require dirname(__DIR__).'/src/CentralInstallationSignedStatusResponse.php';
require dirname(__DIR__).'/src/SignedGrantDeliveryService.php';
require dirname(__DIR__).'/signing/Ed25519StatusReceiptCodec.php';

function signedCheckAssert(bool $yes,string $message):void {
    if(!$yes)throw new RuntimeException('Signed status response: '.$message);
}
function signedCheckUuid():string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);$s=bin2hex($b);
    return substr($s,0,8).'-'.substr($s,8,4).'-'.substr($s,12,4).'-'.
        substr($s,16,4).'-'.substr($s,20);
}
function signedCheckDeny(callable $fn,string $code):void {
    try {$fn();}
    catch(RegistrationError $e){
        signedCheckAssert($e->errorCode===$code,
            'expected '.$code.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Signed status unexpectedly permitted: '.$code);
}
$dsn=getenv('CENTRAL_DB_DSN');
signedCheckAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'independent central PostgreSQL test database');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer=signedCheckUuid();
$foreign=signedCheckUuid();
$enrollment=signedCheckUuid();
$installation=signedCheckUuid();
$installationKeys=sodium_crypto_sign_keypair();
$receiptKeys=sodium_crypto_sign_keypair();
$receiptPublic=InstallationRegistry::base64Url(
    sodium_crypto_sign_publickey($receiptKeys));
$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)")
    ->execute(['id'=>$customer,'name'=>'Synthetic isolated receipt customer']);
$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)")
    ->execute(['id'=>$foreign,'name'=>'Synthetic foreign receipt customer']);
$db->prepare("INSERT INTO installation_enrollments
 (id,customer_id,token_sha256,approved_domain,domain_verified_at,expires_at,claimed_at)
 VALUES(:id,:customer,:hash,'signed-status.example.test',now(),now()+interval '1 hour',now())")
    ->execute(['id'=>$enrollment,'customer'=>$customer,
        'hash'=>hash('sha256',random_bytes(32))]);
$db->prepare("INSERT INTO registered_installations
 (id,customer_id,enrollment_id,approved_domain,ed25519_public_key)
 VALUES(:id,:customer,:enrollment,'signed-status.example.test',:pub)")
    ->execute(['id'=>$installation,'customer'=>$customer,'enrollment'=>$enrollment,
        'pub'=>InstallationRegistry::base64Url(
            sodium_crypto_sign_publickey($installationKeys))]);
$makeRequest=static function(string $asCustomer)use($installation,$installationKeys):array {
    $now=gmdate('Y-m-d\TH:i:s\Z');
    $nonce=InstallationRegistry::base64Url(random_bytes(32));
    $payload=implode("\n",['HAFZATECH-INSTALL-CHECK-V1',
        $installation,$asCustomer,$now,$nonce,'0']);
    return ['installation_id'=>$installation,'customer_id'=>$asCustomer,
        'request_time'=>$now,'nonce'=>$nonce,'known_revision'=>0,
        'signature'=>InstallationRegistry::base64Url(
            sodium_crypto_sign_detached(
                $payload,sodium_crypto_sign_secretkey($installationKeys)))
    ];
};
$status=new InstallationStatusService($db);
$signer=static fn(array $claim):array=>Ed25519StatusReceiptCodec::sign(
    $claim,'worker-test-key',sodium_crypto_sign_secretkey($receiptKeys));
$keys=['worker-test-key'=>$receiptPublic];
$off=new CentralInstallationSignedStatusResponse($status,$signer,$keys);
$on=new CentralInstallationSignedStatusResponse($status,$signer,$keys,true);
$req=$makeRequest($customer);
signedCheckDeny(fn()=>$off->check($req),'signed_status_not_enabled');
signedCheckDeny(fn()=>$on->check($req),'signed_status_not_enabled');
$recorded=$db->prepare("SELECT count(*) FROM central_installation_status_checks
 WHERE installation_id=:installation");
$recorded->execute(['installation'=>$installation]);
signedCheckAssert((int)$recorded->fetchColumn()===0,
    'OFF gate must reject before consuming installation nonce');
putenv('CENTRAL_SIGNED_STATUS_ENABLED=true');
try {
    signedCheckDeny(fn()=>
        (new CentralInstallationSignedStatusResponse(
            $status,$signer,[],true))->check($req),'signed_status_not_enabled');
    $result=$on->check($req);
    signedCheckAssert($result['revision']===0 &&
        $result['status']==='registered_pending_license' &&
        $result['nonce']===$req['nonce'] &&
        $result['registered_installation_id']===$installation,
        'exact installed account, nonce and no invented paid revision');
    $signed=$result['signed_receipt'];
    $bytes=base64_decode(strtr($signed['payload'],'-_','+/'),true);
    $sig=base64_decode(strtr($signed['signature'],'-_','+/'),true);
    signedCheckAssert(sodium_crypto_sign_verify_detached(
        $sig,'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".$bytes,
        sodium_crypto_sign_publickey($receiptKeys)),
        'client-verifiable versioned Ed25519 vendor receipt');
    $claim=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
    signedCheckAssert($claim['customer_id']===$customer &&
        $claim['installation_id']===$installation &&
        $claim['nonce']===$req['nonce'] &&
        $claim['status']==='registered_pending_license' &&
        $claim['revision']===0,
        'signed claim bound to actual authenticated request, not raw input');
    signedCheckDeny(fn()=>$on->check($req),'replayed_check');
    signedCheckDeny(fn()=>$on->check($makeRequest($foreign)),
        'unauthorized_installation');
    $bad=$makeRequest($customer);
    $bad['signature']=InstallationRegistry::base64Url(random_bytes(64));
    signedCheckDeny(fn()=>$on->check($bad),'invalid_installation_proof');

    // The full signed-status + sealed-grant response must authenticate
    // installation proof ONCE. A second check of the same nonce is replay.
    $delivery=new SignedGrantDeliveryService(
        $db,$status,static function(string $sealed):string {
            throw new RuntimeException('No signed sealed grant in synthetic fixture');
        },$keys);
    $combined=new CentralInstallationSignedStatusResponse(
        $status,$signer,$keys,true,$delivery);
    $deliveryReq=$makeRequest($customer);
    putenv('CENTRAL_GRANT_DELIVERY_ENABLED=false');
    signedCheckDeny(fn()=>$combined->check($deliveryReq),
        'grant_delivery_not_enabled');
    putenv('CENTRAL_GRANT_DELIVERY_ENABLED=true');
    $delivered=$combined->check($deliveryReq);
    signedCheckAssert($delivered['nonce']===$deliveryReq['nonce'] &&
        $delivered['revision']===0 &&
        array_key_exists('signed_grant',$delivered) &&
        $delivered['signed_grant']===null &&
        isset($delivered['signed_receipt']),
        'single authenticated request returns signed status and no fabricated license');
    signedCheckDeny(fn()=>$combined->check($deliveryReq),'replayed_check');
    putenv('CENTRAL_GRANT_DELIVERY_ENABLED=false');

    $forged=static fn(array $claim):array=>
        Ed25519StatusReceiptCodec::sign(
            array_replace($claim,['nonce'=>InstallationRegistry::base64Url(random_bytes(32))]),
            'worker-test-key',sodium_crypto_sign_secretkey($receiptKeys));
    signedCheckDeny(fn()=>
        (new CentralInstallationSignedStatusResponse(
            $status,$forged,$keys,true))->check($makeRequest($customer)),
        'invalid_signed_status_receipt');
    $wrongKey=sodium_crypto_sign_keypair();
    $untrusted=static fn(array $claim):array=>
        Ed25519StatusReceiptCodec::sign($claim,'unknown-test-key',
            sodium_crypto_sign_secretkey($wrongKey));
    signedCheckDeny(fn()=>
        (new CentralInstallationSignedStatusResponse(
            $status,$untrusted,$keys,true))->check($makeRequest($customer)),
        'untrusted_status_signer');
    $wrongSig=static fn(array $claim):array=>
        Ed25519StatusReceiptCodec::sign($claim,'worker-test-key',
            sodium_crypto_sign_secretkey($wrongKey));
    signedCheckDeny(fn()=>
        (new CentralInstallationSignedStatusResponse(
            $status,$wrongSig,$keys,true))->check($makeRequest($customer)),
        'invalid_signed_status_receipt');
    $recorded->execute(['installation'=>$installation]);
    signedCheckAssert((int)$recorded->fetchColumn()===5,
        'only five authenticated status probes consumed exactly one nonce each');
} finally {
    putenv('CENTRAL_SIGNED_STATUS_ENABLED=false');
    putenv('CENTRAL_GRANT_DELIVERY_ENABLED=false');
}
echo "PASS: default-OFF isolated signer status composer enforces single installation proof, nonce, exact signed response and pinned worker key\n";
