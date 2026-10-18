<?php
declare(strict_types=1);

use Hafzatech\Central\InstallationRegistry;
use Hafzatech\Central\InstallationStatusService;
use Hafzatech\Central\CentralInstallationSignedStatusResponse;
use Hafzatech\Central\SignedGrantDeliveryService;
use Hafzatech\Central\RegistrationError;
use Hafzatech\Signing\Ed25519StatusReceiptCodec;
use Hafzatech\Signing\Ed25519GrantCodec;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/InstallationStatusService.php';
require dirname(__DIR__).'/src/CentralInstallationSignedStatusResponse.php';
require dirname(__DIR__).'/src/SignedGrantDeliveryService.php';
require dirname(__DIR__).'/signing/Ed25519StatusReceiptCodec.php';
require dirname(__DIR__).'/signing/Ed25519GrantCodec.php';

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
$makeRequest=static function(string $asCustomer,int $knownRevision=0)use($installation,$installationKeys):array {
    $now=gmdate('Y-m-d\TH:i:s\Z');
    $nonce=InstallationRegistry::base64Url(random_bytes(32));
    $payload=implode("\n",['HAFZATECH-INSTALL-CHECK-V1',
        $installation,$asCustomer,$now,$nonce,(string)$knownRevision]);
    return ['installation_id'=>$installation,'customer_id'=>$asCustomer,
        'request_time'=>$now,'nonce'=>$nonce,'known_revision'=>$knownRevision,
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

    // Exercise the actual signed-paid-revision delivery path with isolated,
    // synthetic fixture-only keys. No production signer or payment release.
    $officer=signedCheckUuid();$account=signedCheckUuid();
    $providerLink=signedCheckUuid();$plan=signedCheckUuid();
    $invoice=signedCheckUuid();$license=signedCheckUuid();$revision=signedCheckUuid();
    $db->prepare("INSERT INTO central_staff_users
        (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
        VALUES(:id,:email,'Isolated signer fixture',:hash,decode(:seed,'hex'),now(),'active')")
        ->execute(['id'=>$officer,'email'=>'signer-'.substr($officer,0,8).'@example.test',
            'hash'=>password_hash('synthetic-only',PASSWORD_DEFAULT),
            'seed'=>bin2hex(random_bytes(20))]);
    $db->prepare("INSERT INTO central_vendor_receiving_accounts
        (id,provider_kind,display_label,country_code,receiving_currency,
        encrypted_instructions,status,verified_at,verified_by)
        VALUES(:id,'domestic_bank','Status fixture provider','PK','PKR',
        decode(:cipher,'hex'),'verified',now(),:staff)")
        ->execute(['id'=>$account,'cipher'=>bin2hex(random_bytes(32)),'staff'=>$officer]);
    $db->prepare("INSERT INTO central_customer_provider_assignments
        (id,customer_id,receiving_account_id,enabled)
        VALUES(:id,:customer,:account,true)")
        ->execute(['id'=>$providerLink,'customer'=>$customer,'account'=>$account]);
    $planSnapshot=['max_halls'=>2,'max_staff'=>12,'features'=>['booking'=>true]];
    $db->prepare("INSERT INTO central_vendor_plan_versions
        (id,plan_code,version,term_months,currency,price_minor,entitlements_snapshot)
        VALUES(:id,'isolated_status_delivery',1,12,'PKR',12000,:snapshot)")
        ->execute(['id'=>$plan,'snapshot'=>json_encode($planSnapshot,JSON_THROW_ON_ERROR)]);
    $db->prepare("INSERT INTO central_vendor_invoices
        (id,customer_id,plan_version_id,invoice_number,currency,total_minor,
        receiving_account_id,encrypted_instructions_snapshot,status,issued_at)
        VALUES(:id,:customer,:plan,:number,'PKR',12000,:account,
        decode(:cipher,'hex'),'issued',now())")
        ->execute(['id'=>$invoice,'customer'=>$customer,'plan'=>$plan,
            'number'=>'SIGNED-STATUS-'.substr($invoice,0,8),
            'account'=>$account,'cipher'=>bin2hex(random_bytes(32))]);
    $db->prepare("INSERT INTO central_licenses
        (id,customer_id,installation_id,approved_domain,current_revision)
        VALUES(:id,:customer,:installation,'signed-status.example.test',1)")
        ->execute(['id'=>$license,'customer'=>$customer,'installation'=>$installation]);
    $grantClaim=[
        'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
        'license_id'=>$license,'customer_id'=>$customer,
        'installation_id'=>$installation,'revision'=>1,'term_months'=>12,
        'edition'=>'isolated_status_delivery',
        'approved_domains'=>['signed-status.example.test'],
        'not_before'=>'2026-10-01T00:00:00Z',
        'expires_at'=>'2027-10-01T00:00:00Z',
        'limits'=>['max_halls'=>2,'max_staff'=>12],
        'features'=>['booking'=>true],
    ];
    $grantEnvelope=Ed25519GrantCodec::sign(
        $grantClaim,'worker-test-key',sodium_crypto_sign_secretkey($receiptKeys));
    $sealedKey=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $sealNonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $sealed=$sealNonce.sodium_crypto_secretbox(
        json_encode($grantEnvelope,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        $sealNonce,$sealedKey);
    $db->prepare("INSERT INTO central_paid_license_revisions
        (id,license_id,invoice_id,revision,term_months,begins_at,expires_at,
        plan_snapshot,authorized_officer_id,state,signed_envelope_ciphertext)
        VALUES(:id,:license,:invoice,1,12,'2026-10-01T00:00:00Z',
        '2027-10-01T00:00:00Z',:snapshot,:officer,'signed',decode(:cipher,'hex'))")
        ->execute(['id'=>$revision,'license'=>$license,'invoice'=>$invoice,
            'snapshot'=>json_encode($planSnapshot,JSON_THROW_ON_ERROR),
            'officer'=>$officer,'cipher'=>bin2hex($sealed)]);
    $unseal=static function(string $cipher)use($sealedKey):string {
        $nonce=substr($cipher,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain=sodium_crypto_secretbox_open(
            substr($cipher,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$sealedKey);
        if(!is_string($plain))throw new RuntimeException('Invalid synthetic sealed envelope');
        return $plain;
    };
    $realDelivery=new SignedGrantDeliveryService($db,$status,$unseal,$keys);
    $realCombined=new CentralInstallationSignedStatusResponse(
        $status,$signer,$keys,true,$realDelivery);
    putenv('CENTRAL_GRANT_DELIVERY_ENABLED=true');
    $paidRequest=$makeRequest($customer);
    $paidResponse=$realCombined->check($paidRequest);
    signedCheckAssert($paidResponse['revision']===1 &&
        $paidResponse['status']==='signed_revision_pending_delivery' &&
        isset($paidResponse['signed_grant'],$paidResponse['signed_receipt']) &&
        $paidResponse['signed_grant']===$grantEnvelope,
        'one authenticated nonce returns matching signed status and paid revision');
    $receiptBytes=base64_decode(
        strtr($paidResponse['signed_receipt']['payload'],'-_','+/'),true);
    $receiptClaim=json_decode($receiptBytes,true,16,JSON_THROW_ON_ERROR);
    signedCheckAssert($receiptClaim['nonce']===$paidRequest['nonce'] &&
        $receiptClaim['revision']===1 &&
        $receiptClaim['status']==='signed_revision_pending_delivery',
        'client receipt exactly matches the delivered signed paid revision');
    signedCheckDeny(fn()=>$realCombined->check($paidRequest),'replayed_check');
    // A tampered unsealed envelope cannot be returned as a grant, even if
    // the status worker itself can still produce a valid signed receipt.
    $tamperUnseal=static function(string $cipher)use($grantEnvelope):string {
        $altered=$grantEnvelope;
        $altered['signature']=InstallationRegistry::base64Url(random_bytes(64));
        return json_encode($altered,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    };
    $badDelivery=new SignedGrantDeliveryService($db,$status,$tamperUnseal,$keys);
    $badCombined=new CentralInstallationSignedStatusResponse(
        $status,$signer,$keys,true,$badDelivery);
    signedCheckDeny(fn()=>$badCombined->check($makeRequest($customer)),
        'invalid_grant_signature');
    $existingRequest=$makeRequest($customer,1);
    $existingResponse=$realCombined->check($existingRequest);
    signedCheckAssert($existingResponse['revision']===1 &&
        $existingResponse['signed_grant']===null &&
        isset($existingResponse['signed_receipt']),
        'same paid revision reports signed status without delivering new grant');
    putenv('CENTRAL_GRANT_DELIVERY_ENABLED=false');
    signedCheckDeny(fn()=>$realCombined->check($makeRequest($customer)),
        'grant_delivery_not_enabled');

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
    signedCheckAssert((int)$recorded->fetchColumn()===8,
        'eight authenticated status probes consume exactly one nonce each');
} finally {
    putenv('CENTRAL_SIGNED_STATUS_ENABLED=false');
    putenv('CENTRAL_GRANT_DELIVERY_ENABLED=false');
}
echo "PASS: default-OFF isolated signer status composer enforces single installation proof, nonce, exact signed response and pinned worker key\n";
