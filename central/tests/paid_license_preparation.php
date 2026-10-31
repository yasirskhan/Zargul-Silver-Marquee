<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\InstallationRegistry;
use Hafzatech\Central\LicenseTermCalendar;
use Hafzatech\Central\PaidInvoiceEligibility;
use Hafzatech\Central\PaidLicensePreparation;
use Hafzatech\Central\RegistrationError;
use Hafzatech\Central\StaffRolePermissions;
use Hafzatech\Central\PreparedSignedGrantValidator;
use Hafzatech\Signing\Ed25519GrantCodec;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/StaffRolePermissions.php';
require dirname(__DIR__).'/src/CentralStaffAccess.php';
require dirname(__DIR__).'/src/PaidInvoiceEligibility.php';
require dirname(__DIR__).'/src/LicenseTermCalendar.php';
require dirname(__DIR__).'/src/PaidLicensePreparation.php';
require dirname(__DIR__).'/src/PreparedSignedGrantValidator.php';
require dirname(__DIR__).'/src/DesignerCreditPolicy.php';
require dirname(__DIR__).'/src/CentralStaffDesignerCreditService.php';
require dirname(__DIR__).'/src/SignedGrantFinalization.php';
require dirname(__DIR__).'/src/InstallationStatusService.php';
require dirname(__DIR__).'/src/SignedGrantDeliveryService.php';
require dirname(__DIR__).'/signing/Ed25519StatusReceiptCodec.php';
require dirname(__DIR__).'/signing/ProtectedSignedStatusDelivery.php';
require dirname(__DIR__).'/signing/Ed25519GrantCodec.php';

function issueUuid(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function verifyIssue(bool $assertion,string $label): void {
    if(!$assertion)throw new RuntimeException('FAIL paid issuance preparation: '.$label);
}
function denyIssue(callable $action,string $expected): void {
    try { $action(); } catch(RegistrationError $e) {
        verifyIssue($e->errorCode===$expected,'expected '.$expected.' got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('FAIL expected '.$expected);
}
$dsn=getenv('CENTRAL_DB_DSN');
if(!$dsn || !str_contains($dsn,'hafzatech_central_test'))
    throw new RuntimeException('Use isolated hafzatech_central_test database.');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$customer=issueUuid();$other=issueUuid();$enrollment=issueUuid();
$installation=issueUuid();$billing=issueUuid();$officer=issueUuid();
$provider=issueUuid();$plan=issueUuid();$invoice=issueUuid();
$now=new DateTimeImmutable('2026-10-17T20:00:00Z');
$c=$db->prepare("INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:name)");
$c->execute(['id'=>$customer,'name'=>'Synthetic Paid Venue A']);
$c->execute(['id'=>$other,'name'=>'Synthetic Paid Venue B']);
$db->prepare("INSERT INTO installation_enrollments
    (id,customer_id,token_sha256,approved_domain,domain_verified_at,expires_at,claimed_at)
    VALUES(:id,:customer,:hash,'signed.example.test',now(),now()+interval '1 day',now())")
    ->execute(['id'=>$enrollment,'customer'=>$customer,'hash'=>hash('sha256',random_bytes(32))]);
$installationPair=sodium_crypto_sign_keypair();
$db->prepare("INSERT INTO registered_installations
    (id,customer_id,enrollment_id,approved_domain,ed25519_public_key)
    VALUES(:id,:customer,:enrollment,'signed.example.test',:public)")
    ->execute(['id'=>$installation,'customer'=>$customer,'enrollment'=>$enrollment,
        'public'=>InstallationRegistry::base64Url(sodium_crypto_sign_publickey($installationPair))]);
$s=$db->prepare("INSERT INTO central_staff_users
    (id,email,display_name,password_hash,mfa_enrolled_at,status)
    VALUES(:id,:email,:display,:hash,now(),'active')");
$s->execute(['id'=>$billing,'email'=>'prepare.billing@example.test',
    'display'=>'Independent Billing Reviewer','hash'=>password_hash('synthetic-test-only', PASSWORD_ARGON2ID)]);
$s->execute(['id'=>$officer,'email'=>'prepare.license@example.test',
    'display'=>'Independent License Officer','hash'=>password_hash('synthetic-test-only', PASSWORD_ARGON2ID)]);
$grant=$db->prepare("INSERT INTO central_staff_role_grants
    (id,staff_id,role,reason) VALUES(:id,:staff,:role,'Synthetic independent reviewer')");
$grant->execute(['id'=>issueUuid(),'staff'=>$billing,'role'=>'billing_officer']);
$grant->execute(['id'=>issueUuid(),'staff'=>$officer,'role'=>'license_officer']);
$db->prepare("INSERT INTO central_vendor_receiving_accounts
    (id,provider_kind,display_label,country_code,receiving_currency,
     encrypted_instructions,status,verified_at,verified_by)
    VALUES(:id,'domestic_bank','Verified test bank','PK','PKR',
     decode(:secret,'hex'),'verified',now(),:billing)")
    ->execute(['id'=>$provider,'secret'=>bin2hex(random_bytes(32)),'billing'=>$billing]);
$db->prepare("INSERT INTO central_customer_provider_assignments
    (id,customer_id,receiving_account_id,enabled) VALUES(:id,:customer,:provider,true)")
    ->execute(['id'=>issueUuid(),'customer'=>$customer,'provider'=>$provider]);
$db->prepare("INSERT INTO central_vendor_plan_versions
    (id,plan_code,version,term_months,currency,price_minor,entitlements_snapshot)
    VALUES(:id,'synthetic_paid_prep',1,12,'PKR',10000,:snapshot)")
    ->execute(['id'=>$plan,'snapshot'=>json_encode(['max_halls'=>2,'max_staff'=>10,
        'features'=>['booking'=>true]],JSON_THROW_ON_ERROR)]);
$db->prepare("INSERT INTO central_vendor_invoices
    (id,customer_id,plan_version_id,invoice_number,currency,total_minor,
     receiving_account_id,encrypted_instructions_snapshot,status,issued_at)
    VALUES(:id,:customer,:plan,'SYNTHETIC-LICENSE-1','PKR',10000,:provider,
      decode(:secret,'hex'),'issued',now())")
    ->execute(['id'=>$invoice,'customer'=>$customer,'plan'=>$plan,'provider'=>$provider,
        'secret'=>bin2hex(random_bytes(32))]);
$db->prepare("INSERT INTO central_vendor_payment_evidence
    (id,invoice_id,claimed_reference) VALUES(:id,:invoice,'Unverified transfer screenshot')")
    ->execute(['id'=>issueUuid(),'invoice'=>$invoice]);
$service=new PaidLicensePreparation($db,new PaidInvoiceEligibility($db),new CentralStaffAccess($db));
denyIssue(fn()=>$service->prepare($invoice,$installation,$officer,false,$now),'mfa_required');
denyIssue(fn()=>$service->prepare($invoice,$installation,$officer,true,$now),
    'independent_payment_or_officer_required');
verifyIssue((int)$db->query('SELECT count(*) FROM central_licenses')->fetchColumn()===0,
    'payment proof never created a license');
$db->prepare("INSERT INTO central_vendor_verified_settlements
    (id,invoice_id,receiving_account_id,currency,amount_minor,
     external_settlement_reference,verified_by,verified_at)
    VALUES(:id,:invoice,:provider,'PKR',10000,'prepare-verified-settlement',:billing,now())")
    ->execute(['id'=>issueUuid(),'invoice'=>$invoice,'provider'=>$provider,'billing'=>$billing]);
denyIssue(fn()=>$service->prepare($invoice,issueUuid(),$officer,true,$now),
    'installation_not_eligible');
denyIssue(fn()=>$service->prepare($invoice,$installation,$billing,true,$now),
    'independent_payment_or_officer_required');
$r=$service->prepare($invoice,$installation,$officer,true,$now);
verifyIssue($r['revision']===1 && $r['state']==='awaiting_isolated_signer',
    'initial paid revision staged and not activated');
verifyIssue($r['from']==='2026-10-17T20:00:00Z' &&
    $r['expiry']==='2027-10-17T20:00:00Z','12 month paid UTC period');
$again=$service->prepare($invoice,$installation,$officer,true,$now);
verifyIssue($r===$again,'exact invoice idempotency');
verifyIssue((int)$db->query('SELECT count(*) FROM central_paid_license_revisions')->fetchColumn()===1,
    'no duplicate paid revision');
verifyIssue((int)$db->query('SELECT count(*) FROM central_license_issuance_audit')->fetchColumn()===1,
    'one immutable prep audit');
verifyIssue((int)$db->query("SELECT count(*) FROM central_paid_license_revisions WHERE state='signed'")->fetchColumn()===0,
    'no signer or fabricated paid entitlement');
echo "PASS: central paid license preparation requires verified independent settlement and MFA, is idempotent and leaves grant unsigned\n";
$keypair=sodium_crypto_sign_keypair();
$keyPub=InstallationRegistry::base64Url(sodium_crypto_sign_publickey($keypair));
$validator=new PreparedSignedGrantValidator($db,new PaidInvoiceEligibility($db),
    new CentralStaffAccess($db),['test-key'=>$keyPub]);
$grantClaim=[
    'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
    'license_id'=>$r['license_id'],'installation_id'=>$installation,
    'customer_id'=>$customer,'revision'=>$r['revision'],'term_months'=>12,
    'edition'=>'synthetic_paid_prep','approved_domains'=>['signed.example.test'],
    'not_before'=>$r['from'],'expires_at'=>$r['expiry'],
    'limits'=>['max_halls'=>2,'max_staff'=>10],'features'=>['booking'=>true],
];
$envelope=Ed25519GrantCodec::sign($grantClaim,'test-key',
    sodium_crypto_sign_secretkey($keypair));
$passed=$validator->validate($r['revision_id'],$officer,true,$envelope);
verifyIssue($passed['license_id']===$r['license_id'] && $passed['revision']===1,
    'protected public-key grant envelope matches exact independently paid revision');
denyIssue(fn()=>$validator->validate($r['revision_id'],$officer,false,$envelope),'mfa_required');
$wrong=$grantClaim;
$wrong['approved_domains']=['foreign.example.test'];
$foreign=Ed25519GrantCodec::sign($wrong,'test-key',sodium_crypto_sign_secretkey($keypair));
denyIssue(fn()=>$validator->validate($r['revision_id'],$officer,true,$foreign),
    'grant_does_not_match_paid_revision');
$altered=$envelope;
$altered['payload']=InstallationRegistry::base64Url('{"fake":true}');
denyIssue(fn()=>$validator->validate($r['revision_id'],$officer,true,$altered),
    'invalid_grant_signature');
$db->prepare("UPDATE central_licenses SET current_revision=2 WHERE id=:id")
    ->execute(['id'=>$r['license_id']]);
denyIssue(fn()=>$validator->validate($r['revision_id'],$officer,true,$envelope),
    'signed_grant_not_eligible');
$db->prepare("UPDATE central_licenses SET current_revision=1 WHERE id=:id")
    ->execute(['id'=>$r['license_id']]);
verifyIssue((int)$db->query("SELECT count(*) FROM central_paid_license_revisions WHERE state='signed'")->fetchColumn()===0,
    'validation never signs or activates the grant');
echo "PASS: central protected signed-grant pre-finalization verifies invoice settlement, role, exact plan/date/domain/ID and signature\n";
$sealKey=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$seal=static function(string $plain)use($sealKey):string {
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce.sodium_crypto_secretbox($plain,$nonce,$sealKey);
};
$finalizer=new \Hafzatech\Central\SignedGrantFinalization($db,$validator,$seal);
denyIssue(fn()=>$finalizer->finalize($r['revision_id'],$officer,true,true,$envelope),
    'protected_signer_not_enabled');
putenv('CENTRAL_SIGNER_FINALIZATION_ENABLED=true');
denyIssue(fn()=>$finalizer->finalize($r['revision_id'],$officer,true,false,$envelope),
    'protected_signer_not_enabled');
denyIssue(fn()=>$finalizer->finalize($r['revision_id'],$officer,false,true,$envelope),
    'mfa_required');
$finalized=$finalizer->finalize($r['revision_id'],$officer,true,true,$envelope);
verifyIssue($finalized['state']==='signed' && $finalized['license_id']===$r['license_id'],
    'only exact authenticated grant finalized');
$read=$db->prepare("SELECT state,encode(signed_envelope_ciphertext,'hex') AS sealed
    FROM central_paid_license_revisions WHERE id=:id");
$read->execute(['id'=>$r['revision_id']]);$sealedRow=$read->fetch(PDO::FETCH_ASSOC);
verifyIssue($sealedRow['state']==='signed','revision changed to signed once');
$binary=hex2bin($sealedRow['sealed']);
$nonce=substr($binary,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$decrypted=sodium_crypto_secretbox_open(substr($binary,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
    $nonce,$sealKey);
verifyIssue($decrypted===json_encode($envelope,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
    'signed grant was sealed and privately recoverable with test-only key');
verifyIssue((int)$db->query("SELECT count(*) FROM central_license_signature_events")->fetchColumn()===1,
    'single audited protected signature finalization');
denyIssue(fn()=>$finalizer->finalize($r['revision_id'],$officer,true,true,$envelope),
    'revision_not_pending');
putenv('CENTRAL_SIGNER_FINALIZATION_ENABLED=false');
echo "PASS: protected worker and release gate, atomic signed revision finalization, sealed storage and audit\n";
// Database immutability is enforced even if application code attempts to
// alter an approved settlement or suppress the license audit trail.
$settlementId=$db->query('SELECT id FROM central_vendor_verified_settlements ORDER BY id LIMIT 1')
    ->fetchColumn();
try {
    $db->prepare('UPDATE central_vendor_verified_settlements
        SET amount_minor=amount_minor+1 WHERE id=:id')
        ->execute(['id'=>$settlementId]);
    throw new RuntimeException('FAIL verified central settlement was mutable');
} catch (PDOException $expected) {}
try {
    $db->prepare('DELETE FROM central_license_issuance_audit WHERE revision_id=:id')
        ->execute(['id'=>$r['revision_id']]);
    throw new RuntimeException('FAIL preparation audit was deleted');
} catch (PDOException $expected) {}
try {
    $db->prepare('DELETE FROM central_license_signature_events WHERE revision_id=:id')
        ->execute(['id'=>$r['revision_id']]);
    throw new RuntimeException('FAIL signature audit was deleted');
} catch (PDOException $expected) {}
verifyIssue((int)$db->query('SELECT count(*) FROM central_license_signature_events')->fetchColumn()===1,
    'signature audit event remained after malicious deletes');
echo "PASS: PostgreSQL blocks edits and deletes to verified settlements and paid-license audit records\n";
$unseal=static function(string $sealed)use($sealKey):string{
    $nonce=substr($sealed,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $clear=sodium_crypto_secretbox_open(
        substr($sealed,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$sealKey);
    if($clear===false)throw new RuntimeException('Cannot recover synthetic sealed grant');
    return $clear;
};
$fetcher=new \Hafzatech\Central\SignedGrantDeliveryService(
    $db,new \Hafzatech\Central\InstallationStatusService($db),
    $unseal,['test-key'=>$keyPub]);
$signFetch=static function(string $who,int $known)use($installation,$installationPair):array{
    $nonce=InstallationRegistry::base64Url(random_bytes(32));
    $when=gmdate('Y-m-d\TH:i:s\Z');
    $message=implode("\n",['HAFZATECH-INSTALL-CHECK-V1',$installation,$who,
        $when,$nonce,(string)$known]);
    return [
        'installation_id'=>$installation,'customer_id'=>$who,
        'request_time'=>$when,'nonce'=>$nonce,'known_revision'=>$known,
        'signature'=>InstallationRegistry::base64Url(sodium_crypto_sign_detached(
            $message,sodium_crypto_sign_secretkey($installationPair))),
    ];
};
denyIssue(fn()=>$fetcher->fetch($signFetch($customer,0)),'grant_delivery_not_enabled');
putenv('CENTRAL_GRANT_DELIVERY_ENABLED=true');
$delivered=$fetcher->fetch($signFetch($customer,0));
verifyIssue($delivered['status']==='signed_revision_pending_delivery' &&
    $delivered['revision']===1 && $delivered['signed_grant']===$envelope,
    'only signed stored grant is delivered after authenticated one-use proof');
$again=$fetcher->fetch($signFetch($customer,1));
verifyIssue($again['signed_grant']===null,'up-to-date installation requires no duplicate grant');
denyIssue(fn()=>$fetcher->fetch($signFetch($other,0)),'unauthorized_installation');
putenv('CENTRAL_GRANT_DELIVERY_ENABLED=false');
echo "PASS: protected signed grant readback enforces install signature, customer binding and up-to-date revision\n";
$assembler=new \Hafzatech\Signing\ProtectedSignedStatusDelivery($fetcher);
denyIssue(fn()=>$assembler->build($signFetch($customer,0),'test-key',
    sodium_crypto_sign_secretkey($keypair)),'signed_delivery_not_enabled');
putenv('CENTRAL_GRANT_DELIVERY_ENABLED=true');
putenv('CENTRAL_SIGNED_DELIVERY_ENABLED=true');
$protectedResponse=$assembler->build($signFetch($customer,0),'test-key',
    sodium_crypto_sign_secretkey($keypair));
$receipt=$protectedResponse['signed_receipt'];
$receiptBytes=base64_decode(strtr($receipt['payload'],'-_','+/'),true);
$receiptSignature=base64_decode(strtr($receipt['signature'],'-_','+/'),true);
verifyIssue(sodium_crypto_sign_verify_detached($receiptSignature,
    'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".$receiptBytes,
    sodium_crypto_sign_publickey($keypair)),
    'KMS-style protected signer receipt authentic');
$receiptClaim=json_decode($receiptBytes,true,16,JSON_THROW_ON_ERROR);
verifyIssue($receiptClaim['nonce']===$protectedResponse['nonce'] &&
    $receiptClaim['installation_id']===$installation &&
    $receiptClaim['customer_id']===$customer &&
    $receiptClaim['revision']===$protectedResponse['revision'] &&
    $protectedResponse['signed_grant']===$envelope,
    'paired receipt and signed grant bind exactly to authenticated installation');
putenv('CENTRAL_SIGNED_DELIVERY_ENABLED=false');
putenv('CENTRAL_GRANT_DELIVERY_ENABLED=false');
echo "PASS: isolated signed receipt and grant response bound to fresh authenticated installation nonce\n";





