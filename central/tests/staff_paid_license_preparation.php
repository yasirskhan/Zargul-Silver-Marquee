<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffAccess;
use Hafzatech\Central\CentralStaffCredentialVerifier;
use Hafzatech\Central\CentralStaffLoginRateLimiter;
use Hafzatech\Central\CentralStaffMfaChallenge;
use Hafzatech\Central\CentralStaffPasswordVerifier;
use Hafzatech\Central\CentralStaffSessionAuthorization;
use Hafzatech\Central\CentralStaffSessionService;
use Hafzatech\Central\CentralStaffPaidLicensePreparation;
use Hafzatech\Central\CentralStaffSignedGrantFinalization;
use Hafzatech\Central\InstallationRegistry;
use Hafzatech\Central\LicenseTermCalendar;
use Hafzatech\Central\PaidInvoiceEligibility;
use Hafzatech\Central\PaidLicensePreparation;
use Hafzatech\Central\RegistrationError;
use Hafzatech\Central\StaffRolePermissions;

foreach (['InstallationRegistry','StaffRolePermissions','CentralStaffAccess',
    'PaidInvoiceEligibility','LicenseTermCalendar','PaidLicensePreparation',
    'CentralStaffPasswordVerifier','CentralStaffTotpVerifier',
    'CentralStaffMfaChallenge','CentralStaffCredentialVerifier',
    'CentralStaffLoginRateLimiter','CentralStaffSessionService',
    'CentralStaffSessionAuthorization','CentralStaffPaidLicensePreparation',
    'PreparedSignedGrantValidator','SignedGrantFinalization',
    'CentralStaffSignedGrantFinalization'
] as $name) {
    require dirname(__DIR__).'/src/'.$name.'.php';
}
require dirname(__DIR__).'/signing/Ed25519GrantCodec.php';
function paidSessionAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('Session-bound license: '.$message);
}
function paidSessionDeny(callable $attempt, string $code): void {
    try { $attempt(); } catch (RegistrationError $e) {
        paidSessionAssert($e->errorCode === $code,
            'expected '.$code.', received '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Session-bound license: expected denial '.$code);
}
function paidSessionUuid(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
    $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
        substr($h,16,4).'-'.substr($h,20);
}
function paidSessionTotp(string $seed): string {
    $n=intdiv(time(),30);
    $mac=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),$seed,true);
    $offset=ord($mac[19])&15;
    $number=unpack('N',substr($mac,$offset,4))[1]&0x7fffffff;
    return str_pad((string)($number%1000000),6,'0',STR_PAD_LEFT);
}
$dsn=getenv('CENTRAL_DB_DSN');
paidSessionAssert(is_string($dsn)&&str_contains($dsn,'hafzatech_central_test'),
    'must use isolated central PostgreSQL test database');
$db=new PDO($dsn,getenv('CENTRAL_DB_USERNAME'),getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$company=paidSessionUuid();$other=paidSessionUuid();$enrollment=paidSessionUuid();
$installation=paidSessionUuid();$invoice=paidSessionUuid();$provider=paidSessionUuid();
$plan=paidSessionUuid();$billing=paidSessionUuid();$officer=paidSessionUuid();
$foreign=paidSessionUuid();
$seed='12345678901234567890';$password='synthetic-paid-session-password';
$c=$db->prepare('INSERT INTO vendor_customers(id,legal_name) VALUES(:id,:label)');
$c->execute(['id'=>$company,'label'=>'License Session Venue']);
$c->execute(['id'=>$other,'label'=>'Foreign License Session Venue']);
$db->prepare("INSERT INTO installation_enrollments
    (id,customer_id,token_sha256,approved_domain,domain_verified_at,expires_at,claimed_at)
    VALUES(:id,:customer,:digest,'licensed-session.example.test',now(),
    now()+interval '1 day',now())")->execute([
    'id'=>$enrollment,'customer'=>$company,'digest'=>hash('sha256',random_bytes(32))
]);
$keypair=sodium_crypto_sign_keypair();
$db->prepare("INSERT INTO registered_installations
    (id,customer_id,enrollment_id,approved_domain,ed25519_public_key)
    VALUES(:id,:customer,:enrollment,'licensed-session.example.test',:key)")
    ->execute(['id'=>$installation,'customer'=>$company,'enrollment'=>$enrollment,
        'key'=>InstallationRegistry::base64Url(sodium_crypto_sign_publickey($keypair))]);
$makeUser=$db->prepare("INSERT INTO central_staff_users
    (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
    VALUES(:id,:email,'Synthetic Vendor Operator',:hash,decode(:seed,'hex'),now(),'active')");
$grant=$db->prepare("INSERT INTO central_staff_role_grants
    (id,staff_id,role,customer_scope_id,reason)
    VALUES(:id,:staff,:role,:scope,'Synthetic paid issuance scoping')");
foreach ([
    [$billing,'billing','billing_officer',null],
    [$officer,'officer','license_officer',null],
    [$foreign,'foreign','license_officer',$other]
] as [$id,$label,$role,$scope]) {
    $makeUser->execute(['id'=>$id,'email'=>'paid-session-'.$label.'@example.test',
        'hash'=>password_hash($password,PASSWORD_ARGON2ID),'seed'=>bin2hex($seed)]);
    $grant->execute(['id'=>paidSessionUuid(),'staff'=>$id,'role'=>$role,'scope'=>$scope]);
}
$db->prepare("INSERT INTO central_vendor_receiving_accounts
 (id,provider_kind,display_label,country_code,receiving_currency,
 encrypted_instructions,status,verified_at,verified_by)
 VALUES(:id,'domestic_bank','Synthetic verified receiving bank','PK','PKR',
 decode(:cipher,'hex'),'verified',now(),:billing)")
 ->execute(['id'=>$provider,'cipher'=>bin2hex(random_bytes(32)),'billing'=>$billing]);
$db->prepare("INSERT INTO central_customer_provider_assignments
 (id,customer_id,receiving_account_id,enabled) VALUES(:id,:customer,:account,true)")
 ->execute(['id'=>paidSessionUuid(),'customer'=>$company,'account'=>$provider]);
$db->prepare("INSERT INTO central_vendor_plan_versions
 (id,plan_code,version,term_months,currency,price_minor,entitlements_snapshot)
 VALUES(:id,'synthetic_session_plan',1,12,'PKR',21000,:snapshot)")
 ->execute(['id'=>$plan,'snapshot'=>json_encode([
   'max_halls'=>2,'max_staff'=>12,'features'=>['booking'=>true]
 ],JSON_THROW_ON_ERROR)]);
$db->prepare("INSERT INTO central_vendor_invoices
 (id,customer_id,plan_version_id,invoice_number,currency,total_minor,
 receiving_account_id,encrypted_instructions_snapshot,status,issued_at)
 VALUES(:id,:customer,:plan,:number,'PKR',21000,:provider,
 decode(:cipher,'hex'),'issued',now())")
 ->execute(['id'=>$invoice,'customer'=>$company,'plan'=>$plan,
   'number'=>'SYNTHETIC-SESSION-'.substr($invoice,0,8),'provider'=>$provider,
   'cipher'=>bin2hex(random_bytes(32))]);
$db->prepare("INSERT INTO central_vendor_payment_evidence
 (id,invoice_id,claimed_reference) VALUES(:id,:invoice,'NOT A SETTLEMENT')")
 ->execute(['id'=>paidSessionUuid(),'invoice'=>$invoice]);
$sessions=new CentralStaffSessionService($db,
    new CentralStaffCredentialVerifier(
        new CentralStaffPasswordVerifier($db),
        new CentralStaffMfaChallenge($db,static fn(string $sealed):string=>$sealed)
    ),new CentralStaffLoginRateLimiter($db,str_repeat('x',32)));
$access=new CentralStaffAccess($db);
$service=new CentralStaffPaidLicensePreparation($db,$sessions,
    new CentralStaffSessionAuthorization($sessions,$access),
    new PaidLicensePreparation($db,new PaidInvoiceEligibility($db),$access));
$token=paidSessionTotp($seed);
$issuer=$sessions->login('paid-session-officer@example.test',$password,$token,'192.0.2.95');
$billingSession=$sessions->login('paid-session-billing@example.test',$password,$token,'192.0.2.96');
$foreignSession=$sessions->login('paid-session-foreign@example.test',$password,$token,'192.0.2.97');
paidSessionAssert(is_string($issuer)&&is_string($billingSession)&&
    is_string($foreignSession),'genuine independent vendor password+TOTP sessions');
$now=new DateTimeImmutable('2026-10-18T12:00:00Z');
paidSessionDeny(fn()=>$service->prepare(str_repeat('0',64),$invoice,$installation,$now),
    'staff_session_required');
paidSessionDeny(fn()=>$service->prepare($billingSession,$invoice,$installation,$now),
    'license_officer_required');
paidSessionDeny(fn()=>$service->prepare($foreignSession,$invoice,$installation,$now),
    'license_officer_required');
paidSessionDeny(fn()=>$service->prepare($issuer,'bad-uuid',$installation,$now),
    'invalid_license_request');
paidSessionDeny(fn()=>$service->prepare($issuer,$invoice,paidSessionUuid(),$now),
    'installation_not_eligible');
paidSessionDeny(fn()=>$service->prepare($issuer,$invoice,$installation,$now),
    'independent_payment_or_officer_required');
$invoiceCount=$db->prepare('SELECT count(*) FROM central_paid_license_revisions
    WHERE invoice_id=:invoice');
$invoiceCount->execute(['invoice'=>$invoice]);
paidSessionAssert((int)$invoiceCount->fetchColumn()===0,
    'customer evidence cannot activate or stage THIS unpaid entitlement');
$db->prepare("INSERT INTO central_vendor_verified_settlements
 (id,invoice_id,receiving_account_id,currency,amount_minor,
 external_settlement_reference,verified_by,verified_at)
 VALUES(:id,:invoice,:provider,'PKR',21000,:reference,:billing,now())")
 ->execute(['id'=>paidSessionUuid(),'invoice'=>$invoice,'provider'=>$provider,
   'reference'=>'session-settled-'.substr($invoice,0,8),'billing'=>$billing]);
$prepared=$service->prepare($issuer,$invoice,$installation,$now);
paidSessionAssert($prepared['revision']===1 &&
    $prepared['state']==='awaiting_isolated_signer' &&
    $prepared['expiry']==='2027-10-18T12:00:00Z',
    'real issuer session prepares exactly a paid unsigned 12-month grant');
paidSessionAssert($service->prepare($issuer,$invoice,$installation,$now)===$prepared,
    'same authorized staff retry is idempotent');
$auditCount=$db->prepare('SELECT count(*) FROM central_license_issuance_audit
    WHERE invoice_id=:invoice');
$auditCount->execute(['invoice'=>$invoice]);
paidSessionAssert((int)$auditCount->fetchColumn()===1,
    'exactly one durable license preparation event for THIS invoice');
$signedCount=$db->prepare("SELECT count(*) FROM central_paid_license_revisions
    WHERE invoice_id=:invoice AND state='signed'");
$signedCount->execute(['invoice'=>$invoice]);
paidSessionAssert((int)$signedCount->fetchColumn()===0,
    'THIS invoice remains unsigned; no activation occurred');
// Test-only, isolated Ed25519 fixture; no signer private key enters an
// installation or production vault. Keep all production release gates OFF.
$keypair=sodium_crypto_sign_keypair();
$public=InstallationRegistry::base64Url(sodium_crypto_sign_publickey($keypair));
$validator=new Hafzatech\Central\PreparedSignedGrantValidator(
    $db,new PaidInvoiceEligibility($db),$access,['session-test-key'=>$public]);
$vault=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$seal=static function(string $clear)use($vault):string{
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce.sodium_crypto_secretbox($clear,$nonce,$vault);
};
$finalize=new CentralStaffSignedGrantFinalization(
    $db,$sessions,new CentralStaffSessionAuthorization($sessions,$access),
    new Hafzatech\Central\SignedGrantFinalization($db,$validator,$seal));
$claim=[
    'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
    'license_id'=>$prepared['license_id'],'customer_id'=>$company,
    'installation_id'=>$installation,'revision'=>1,'term_months'=>12,
    'edition'=>'synthetic_session_plan','approved_domains'=>['licensed-session.example.test'],
    'not_before'=>$prepared['from'],'expires_at'=>$prepared['expiry'],
    'limits'=>['max_halls'=>2,'max_staff'=>12],'features'=>['booking'=>true],
];
$envelope=Hafzatech\Signing\Ed25519GrantCodec::sign(
    $claim,'session-test-key',sodium_crypto_sign_secretkey($keypair));
paidSessionDeny(fn()=>$finalize->finalize(str_repeat('0',64),
    $prepared['revision_id'],true,$envelope),'staff_session_required');
paidSessionDeny(fn()=>$finalize->finalize($billingSession,
    $prepared['revision_id'],true,$envelope),'license_officer_required');
paidSessionDeny(fn()=>$finalize->finalize($foreignSession,
    $prepared['revision_id'],true,$envelope),'license_officer_required');
paidSessionDeny(fn()=>$finalize->finalize($issuer,'invalid',true,$envelope),
    'invalid_license_request');
paidSessionDeny(fn()=>$finalize->finalize($issuer,paidSessionUuid(),true,$envelope),
    'signed_grant_not_eligible');
paidSessionDeny(fn()=>$finalize->finalize($issuer,$prepared['revision_id'],
    true,$envelope),'protected_signer_not_enabled');
putenv('CENTRAL_SIGNER_FINALIZATION_ENABLED=true');
try {
    paidSessionDeny(fn()=>$finalize->finalize($issuer,$prepared['revision_id'],
        false,$envelope),'protected_signer_not_enabled');
    $result=$finalize->finalize($issuer,$prepared['revision_id'],true,$envelope);
    paidSessionAssert($result['state']==='signed' &&
        $result['license_id']===$prepared['license_id'],
        'only active original issuer can finalize externally signed paid revision');
    paidSessionDeny(fn()=>$finalize->finalize($issuer,$prepared['revision_id'],
        true,$envelope),'revision_not_pending');
} finally {
    putenv('CENTRAL_SIGNER_FINALIZATION_ENABLED=false');
}
$signatureCount=$db->prepare('SELECT count(*) FROM central_license_signature_events
    WHERE revision_id=:rev AND authorized_officer_id=:officer');
$signatureCount->execute(['rev'=>$prepared['revision_id'],'officer'=>$officer]);
paidSessionAssert((int)$signatureCount->fetchColumn()===1,
    'exactly one immutable signing audit tied to authenticated vendor officer');
$db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
    WHERE staff_id=:id AND role='license_officer'")
 ->execute(['id'=>$officer]);
paidSessionDeny(fn()=>$service->prepare($issuer,$invoice,$installation,$now),
    'staff_session_required');
paidSessionAssert($sessions->staffFor($issuer)===null,
    'revoked officer role invalidates previously valid bearer');
echo "PASS: vendor-session-only paid license preparation, independent payment settlement, customer scope, idempotency and live role revocation\n";
