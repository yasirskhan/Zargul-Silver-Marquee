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
use Hafzatech\Central\CentralStaffSignedGrantManifest;
use Hafzatech\Central\CentralStaffLicenseSigningQueue;
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
    'CentralStaffSignedGrantFinalization','CentralStaffSignedGrantManifest',
    'CentralStaffLicenseSigningQueue',
    'CentralStaffCsrfTokenService','CentralStaffHttpPolicy',
    'CentralStaffProtectedHttpBoundary','CentralStaffPaymentEvidenceQueue',
    'CentralStaffInvoicePaymentOverview','CentralStaffPaymentEvidenceReviewService',
    'CentralStaffPaymentEvidenceHttpAdapter','CentralStaffBillingDashboardRenderer',
    'CentralStaffVendorInvoiceIssuer','CentralStaffVendorInvoiceReceiptRenderer',
    'CentralStaffVendorInvoiceDirectory','CentralStaffVendorInvoiceDirectoryRenderer',
    'CentralStaffVendorPlanPublisher','CentralStaffVendorPlanCatalogRenderer',
    'CentralStaffVendorPlanCatalogReader','CentralStaffPortalHome','CentralStaffCustomerDirectory',
    'CentralStaffCustomerDirectoryRenderer',
    'CentralStaffPortalHttpRouter','CentralStaffPortalPrivateResponse',
    'CentralStaffLicenseQueueHtmlRenderer','CentralStaffCustomerLookup',
    'CentralStaffPortalDashboard','CentralStaffSupportTicketQueue',
    'CentralStaffSupportQueueHtmlRenderer','SupportAgentReplyService',
    'CentralStaffSessionSupportReply'
] as $name) {
    require dirname(__DIR__).'/src/'.$name.'.php';
}
require dirname(__DIR__).'/src/InstallationStatusService.php';
require dirname(__DIR__).'/src/SignedGrantDeliveryService.php';
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
$installationKeys=$keypair;
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
$queue=new CentralStaffLicenseSigningQueue(
    $db,new CentralStaffSessionAuthorization($sessions,$access));
paidSessionDeny(fn()=>$queue->listPending(str_repeat('0',64),$company),
    'license_officer_required');
paidSessionDeny(fn()=>$queue->listPending($billingSession,$company),
    'license_officer_required');
paidSessionDeny(fn()=>$queue->listPending($foreignSession,$company),
    'license_officer_required');
paidSessionDeny(fn()=>$queue->listPending($issuer,'invalid'),
    'invalid_license_request');
paidSessionDeny(fn()=>$queue->listPending($issuer,$company,-1),
    'invalid_license_request');
paidSessionDeny(fn()=>$queue->listPending($issuer,$company,0,101),
    'invalid_license_request');
$pending=$queue->listPending($issuer,$company,0,1);
paidSessionAssert(count($pending)===1 &&
    $pending[0]['revision_id']===$prepared['revision_id'] &&
    $pending[0]['invoice_number']===('SYNTHETIC-SESSION-'.substr($invoice,0,8)) &&
    $pending[0]['state']==='awaiting_isolated_signer',
    'vendor officer sees only a real customer-scoped pending signed revision');
paidSessionAssert(array_keys($pending[0])===[
    'revision_id','license_id','revision','state','created_at',
    'expires_at','invoice_number','approved_domain'
], 'queue discloses only bounded safe license metadata');
paidSessionAssert($queue->listPending($issuer,$company,1,1)===[],
    'pending signing queue has bounded predictable pagination');
// Read-only, independently default-OFF license queue page under a real
// current MFA session and customer scope. This does not invoke any signer.
$queueCsrfTokens=new \Hafzatech\Central\CentralStaffCsrfTokenService($db,$sessions);
$issuerCsrf=$queueCsrfTokens->issue($issuer);
$billingCsrf=$queueCsrfTokens->issue($billingSession);
$foreignCsrf=$queueCsrfTokens->issue($foreignSession);
paidSessionAssert(is_string($issuerCsrf)&&is_string($billingCsrf)&&
 is_string($foreignCsrf),'three vendor sessions receive independent CSRF proofs');
$queueBoundary=new \Hafzatech\Central\CentralStaffProtectedHttpBoundary(
 $sessions,$queueCsrfTokens,
 new CentralStaffSessionAuthorization($sessions,$access),true);
$queueAuth=new CentralStaffSessionAuthorization($sessions,$access);
$unusedBilling=new \Hafzatech\Central\CentralStaffPaymentEvidenceHttpAdapter(
 $db,$queueBoundary,
 new \Hafzatech\Central\CentralStaffPaymentEvidenceQueue($db,$queueAuth),
 new \Hafzatech\Central\CentralStaffPaymentEvidenceReviewService(
  $db,$sessions,$queueAuth),
 new \Hafzatech\Central\CentralStaffInvoicePaymentOverview(
  $db,$sessions,$queueAuth),false);
$queuePortalOff=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
 $queueBoundary,$unusedBilling);
$queuePortal=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
 $queueBoundary,$unusedBilling,true,$queue);
$queueRequest=[
 'HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'lichall.hafzatech.com',
 'REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/staff/licenses/pending',
 'HTTP_ORIGIN'=>'https://lichall.hafzatech.com',
 'HTTP_SEC_FETCH_SITE'=>'same-origin','REMOTE_ADDR'=>'192.0.2.96'
];
$issuerCookie=[
 \Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME=>$issuer
];
$billingCookie=[
 \Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME=>$billingSession
];
$foreignCookie=[
 \Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME=>$foreignSession
];
paidSessionDeny(fn()=>$queuePortalOff->dispatch(
 $queueRequest,$issuerCookie,['customer_id'=>$company],$issuerCsrf),
 'staff_portal_not_enabled');
$queuePage=$queuePortal->dispatch(
 $queueRequest,$issuerCookie,['customer_id'=>$company],$issuerCsrf);
paidSessionAssert($queuePage['kind']==='private_license_html' &&
 str_contains($queuePage['html'],$pending[0]['invoice_number']) &&
 str_contains($queuePage['html'],$prepared['license_id']) &&
 str_contains($queuePage['html'],'Awaiting isolated signer') &&
 !str_contains($queuePage['html'],$issuer) &&
 !str_contains($queuePage['html'],$issuerCsrf),
 'authorized officer sees escaped scoped pending revision without session secrets');
$queueWire=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
 ->compose($queuePage);
paidSessionAssert($queueWire['status']===200 &&
 $queueWire['cookies']===[] &&
 $queueWire['headers']['Cache-Control']==='no-store, private' &&
 str_contains($queueWire['headers']['Content-Security-Policy'],"default-src 'none'"),
 'private licensing response is cookie-free and cache-disabled');
paidSessionDeny(fn()=>$queuePortal->dispatch(
 $queueRequest,$billingCookie,['customer_id'=>$company],$billingCsrf),
 'staff_portal_denied');
paidSessionDeny(fn()=>$queuePortal->dispatch(
 $queueRequest,$foreignCookie,['customer_id'=>$company],$foreignCsrf),
 'staff_portal_denied');
$globalOfficerOther=$queuePortal->dispatch(
 $queueRequest,$issuerCookie,['customer_id'=>$other],$issuerCsrf);
paidSessionAssert($globalOfficerOther['kind']==='private_license_html' &&
 str_contains($globalOfficerOther['html'],'No pending signed-revision requests') &&
 !str_contains($globalOfficerOther['html'],$prepared['license_id']),
 'globally authorized officer sees no foreign customer queue records');
paidSessionDeny(fn()=>$queuePortal->dispatch(
 $queueRequest,$issuerCookie,
 ['customer_id'=>$company,'revision_id'=>$prepared['revision_id']],$issuerCsrf),
 'invalid_license_request');
paidSessionDeny(fn()=>$queuePortal->dispatch(
 $queueRequest,$issuerCookie,['customer_id'=>$company],str_repeat('0',64)),
 'staff_portal_denied');
$queueCross=$queueRequest;$queueCross['HTTP_ORIGIN']='https://evil.example.test';
paidSessionDeny(fn()=>$queuePortal->dispatch(
 $queueCross,$issuerCookie,['customer_id'=>$company],$issuerCsrf),
 'staff_portal_denied');
$queuePut=$queueRequest;$queuePut['REQUEST_METHOD']='PUT';
paidSessionDeny(fn()=>$queuePortal->dispatch(
 $queuePut,$issuerCookie,['customer_id'=>$company],$issuerCsrf),
 'staff_portal_denied');
$malicious=\Hafzatech\Central\CentralStaffLicenseQueueHtmlRenderer::render([[
 'invoice_number'=>'<script>alert(1)</script>',
 'approved_domain'=>'"><img src=x onerror=alert(1)>',
 'license_id'=>$prepared['license_id'],'revision'=>1,
 'expires_at'=>'2027-10-18'
]]);
paidSessionAssert(str_contains($malicious,'&lt;script&gt;') &&
 str_contains($malicious,'&lt;img') &&
 !str_contains($malicious,'<script>') &&
 !str_contains($malicious,'<img'),
 'license queue escapes untrusted invoice and approved domain fields');

// Exact customer-id vendor lookup has NO arbitrary search/enumeration and uses
// current MFA/scoped permissions independently at the data and HTTP layers.
// Current MFA company directory NEVER enumerates foreign tenant details.
// Global billing may access two active businesses; a scoped license officer
// may see only their designated customer, even for substring probes.
$directory=new \Hafzatech\Central\CentralStaffCustomerDirectory(
 $db,$sessions,$queueAuth);
$directoryRouter=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
 $queueBoundary,$unusedBilling,true,customerDirectory:$directory);
$directoryRequest=$queueRequest;
$directoryRequest['REQUEST_URI']='/staff/customers/directory';
$directoryPage=$directoryRouter->dispatch($directoryRequest,
 $foreignCookie,['search'=>'Foreign License'],$foreignCsrf);
paidSessionAssert($directoryPage['kind']==='private_customer_directory_html' &&
 str_contains($directoryPage['html'],'Foreign License Session Venue') &&
 str_contains($directoryPage['html'],$other) &&
 !str_contains($directoryPage['html'],$company) &&
 !str_contains($directoryPage['html'],$foreignSession),
 'scoped licensing officer sees only assigned customer, never bearer');
$noForeignLeak=$directoryRouter->dispatch($directoryRequest,
 $foreignCookie,['search'=>'License Session'],$foreignCsrf);
paidSessionAssert(!str_contains($noForeignLeak['html'],$company) &&
 str_contains($noForeignLeak['html'],'No accessible customers'),
 'scoped customer directory cannot enumerate unrelated company');
$globalDirectory=$directoryRouter->dispatch($directoryRequest,
 $billingCookie,['search'=>'License Session'],$billingCsrf);
paidSessionAssert(str_contains($globalDirectory['html'],$company) &&
 !str_contains($globalDirectory['html'],'Foreign License Session Venue') &&
 str_contains($globalDirectory['html'],'name="staff_csrf"') &&
 str_contains($globalDirectory['html'],'action="/staff/dashboard"'),
 'global Billing Officer can find permitted customer by known name');
$directoryResponse=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
 ->compose($globalDirectory);
paidSessionAssert($directoryResponse['cookies']===[] &&
 $directoryResponse['headers']['Cache-Control']==='no-store, private' &&
 str_contains($directoryResponse['headers']['Content-Security-Policy'],
    "default-src 'none'"), 'customer directory cannot cache or leak cookies');
paidSessionDeny(fn()=>$directoryRouter->dispatch($directoryRequest,
 $foreignCookie,['search'=>'Foreign','role'=>'admin'],$foreignCsrf),
 'invalid_customer_directory_request');
paidSessionDeny(fn()=>$directoryRouter->dispatch($directoryRequest,
 $foreignCookie,['search'=>'Foreign'],str_repeat('0',64)),
 'staff_portal_denied');
$alienDirectory=$directoryRequest;
$alienDirectory['HTTP_ORIGIN']='https://untrusted.example.test';
paidSessionDeny(fn()=>$directoryRouter->dispatch($alienDirectory,
 $foreignCookie,['search'=>'Foreign'],$foreignCsrf),'staff_portal_denied');
paidSessionDeny(fn()=>$directory->search(str_repeat('0',64),'Foreign'),
 'staff_session_required');
paidSessionDeny(fn()=>$directory->search($billingSession,str_repeat('z',65)),
 'invalid_customer_directory_request');
paidSessionDeny(fn()=>$directory->search($billingSession,"\xC3\x28"),
 'invalid_customer_directory_request');
$escapedName=\Hafzatech\Central\CentralStaffCustomerDirectoryRenderer::html([
 ['id'=>$other,'legal_name'=>'<script>alert(1)</script>']
],$foreignCsrf,'<tag>');
paidSessionAssert(!str_contains($escapedName,'<script>') &&
 str_contains($escapedName,'&lt;script&gt;') &&
 str_contains($escapedName,'value="&lt;tag&gt;"'),
 'HTML injection in a business name or search prefix remains escaped');
$customerReader=new \Hafzatech\Central\CentralStaffCustomerLookup(
 $db,$queueAuth);
$customerPortal=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
 $queueBoundary,$unusedBilling,true,$queue,$customerReader);
$lookupRequest=$queueRequest;
$lookupRequest['REQUEST_URI']='/staff/customers/lookup';
$companyRecord=$customerPortal->dispatch(
 $lookupRequest,$issuerCookie,['customer_id'=>$company],$issuerCsrf);
paidSessionAssert($companyRecord['kind']==='private_customer_html' &&
 str_contains($companyRecord['html'],'License Session Venue') &&
 str_contains($companyRecord['html'],$company) &&
 !str_contains($companyRecord['html'],$issuer) &&
 !str_contains($companyRecord['html'],$issuerCsrf),
 'license officer receives only scoped active business identity, not secrets');
$customerWire=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
 ->compose($companyRecord);
paidSessionAssert($customerWire['cookies']===[] &&
 $customerWire['headers']['Cache-Control']==='no-store, private',
 'customer lookup stays private, uncached and cookie-free');
$billingLookup=$customerPortal->dispatch(
 $lookupRequest,$billingCookie,['customer_id'=>$company],$billingCsrf);
paidSessionAssert(str_contains($billingLookup['html'],'License Session Venue'),
 'billing officer can view only authorized read-only customer identity');
paidSessionDeny(fn()=>$customerPortal->dispatch(
 $lookupRequest,$foreignCookie,['customer_id'=>$company],$foreignCsrf),
 'staff_portal_denied');
$foreignCustomer=$customerPortal->dispatch(
 $lookupRequest,$issuerCookie,['customer_id'=>$other],$issuerCsrf);
paidSessionAssert(str_contains($foreignCustomer['html'],'Foreign License Session Venue') &&
 !str_contains($foreignCustomer['html'],'<dd>License Session Venue</dd>'),
 'global license officer receives only requested foreign active customer identity');
paidSessionDeny(fn()=>$customerPortal->dispatch(
 $lookupRequest,$issuerCookie,['customer_id'=>paidSessionUuid()],$issuerCsrf),
 'customer_not_found');
paidSessionDeny(fn()=>$customerPortal->dispatch(
 $lookupRequest,$issuerCookie,['customer_id'=>'invalid'],$issuerCsrf),
 'invalid_customer_lookup');
paidSessionDeny(fn()=>$customerPortal->dispatch(
 $lookupRequest,$issuerCookie,
 ['customer_id'=>$company,'select'=>'all'],$issuerCsrf),
 'invalid_customer_lookup');
paidSessionDeny(fn()=>$customerPortal->dispatch(
 $lookupRequest,$issuerCookie,['customer_id'=>$company],str_repeat('0',64)),
 'staff_portal_denied');
$wrongLookupOrigin=$lookupRequest;
$wrongLookupOrigin['HTTP_ORIGIN']='https://evil.example';
paidSessionDeny(fn()=>$customerPortal->dispatch(
 $wrongLookupOrigin,$issuerCookie,['customer_id'=>$company],$issuerCsrf),
 'staff_portal_denied');
$hostileCustomer=\Hafzatech\Central\CentralStaffCustomerLookup::html([
 'id'=>$company,
 'legal_name'=>'<script>alert(1)</script><img src=x onerror=alert(2)>',
 'status'=>'active'
]);
paidSessionAssert(str_contains($hostileCustomer,'&lt;script&gt;') &&
 str_contains($hostileCustomer,'&lt;img') &&
 !str_contains($hostileCustomer,'<script>') &&
 !str_contains($hostileCustomer,'<img'),
 'customer display escapes all owner-supplied registered business text');

// Read-only role-specific dashboard derives permissions from CURRENT central
// MFA grants rather than any user-supplied role or privilege assertion.
$dashboardService=new \Hafzatech\Central\CentralStaffPortalDashboard(
 $queueAuth,$customerReader);
$dashboardRouter=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
 $queueBoundary,$unusedBilling,true,$queue,$customerReader,$dashboardService);
$dashboardHttp=$queueRequest;$dashboardHttp['REQUEST_URI']='/staff/dashboard';
$issuerDashboard=$dashboardRouter->dispatch(
 $dashboardHttp,$issuerCookie,['customer_id'=>$company],$issuerCsrf);
paidSessionAssert($issuerDashboard['kind']==='private_dashboard_html' &&
 str_contains($issuerDashboard['html'],'action="/staff/licenses/pending"') &&
 !str_contains($issuerDashboard['html'],'action="/staff/billing/overview"') &&
 str_contains($issuerDashboard['html'],'License Officer') &&
 !str_contains($issuerDashboard['html'],'Billing Officer') &&
 !str_contains($issuerDashboard['html'],$issuer),
 'current License Officer sees only assigned licensing module');
$billingDashboard=$dashboardRouter->dispatch(
 $dashboardHttp,$billingCookie,['customer_id'=>$company],$billingCsrf);
paidSessionAssert(str_contains($billingDashboard['html'],'Billing Officer') &&
 str_contains($billingDashboard['html'],'action="/staff/billing/overview"') &&
 !str_contains($billingDashboard['html'],'action="/staff/licenses/pending"') &&
 !str_contains($billingDashboard['html'],'<h2>License Officer</h2>'),
 'current Billing Officer sees only billing module and no license signing');
// Protected INTERNAL vendor invoice issue operation. It uses the same MFA
// session cookie, two-secret CSRF and same-origin boundary as every operator
// mutation. Even when an invoice is issued, no money or signed license moves.
$invoiceIssuer=new \Hafzatech\Central\CentralStaffVendorInvoiceIssuer(
 $db,$sessions,$queueAuth,true);
$invoiceRouter=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
 $queueBoundary,$unusedBilling,true,invoiceIssuer:$invoiceIssuer);
$invoiceHttp=$queueRequest;
$invoiceHttp['REQUEST_URI']='/staff/billing/invoices/issue';
$invoiceFields=['customer_id'=>$company,'plan_version_id'=>$plan,
 'receiving_account_id'=>$provider,'request_id'=>paidSessionUuid()];
putenv('CENTRAL_VENDOR_INVOICE_ISSUANCE_ENABLED=false');
paidSessionDeny(fn()=>$invoiceRouter->dispatch(
 $invoiceHttp,$billingCookie,$invoiceFields,$billingCsrf),
 'vendor_invoice_issuance_not_enabled');
putenv('CENTRAL_VENDOR_INVOICE_ISSUANCE_ENABLED=true');
try {
 $invoicePage=$invoiceRouter->dispatch(
  $invoiceHttp,$billingCookie,$invoiceFields,$billingCsrf);
 paidSessionAssert($invoicePage['kind']==='private_vendor_invoice_issued_html' &&
  str_contains($invoicePage['html'],'HAFZATECH subscription invoice') &&
  str_contains($invoicePage['html'],'21000') &&
  str_contains($invoicePage['html'],'NOT verified payment') &&
  !str_contains($invoicePage['html'],$billingSession) &&
  !str_contains($invoicePage['html'],$billingCsrf),
  'staff issued real fixed-price invoice with safe private receipt only');
 $offers=$invoiceIssuer->offers($billingSession,$company);
 $eligible=array_values(array_filter($offers,
  static fn(array $v):bool=>$v['plan_version_id']===$plan &&
   $v['receiving_account_id']===$provider &&
   $v['currency']==='PKR' && (int)$v['term_months']===12));
 paidSessionAssert(count($eligible)===1 &&
  !isset($eligible[0]['encrypted_instructions']),
  'eligible plan and assigned receiver visible without banking secrets');
 paidSessionDeny(fn()=>$invoiceIssuer->offers($issuer,$company),
  'billing_invoice_issuer_required');
 $invoiceDashboard=new \Hafzatech\Central\CentralStaffPortalDashboard(
  $queueAuth,$customerReader,$invoiceIssuer);
 $invoiceDashboardHtml=$invoiceDashboard->render(
  $billingSession,$company,$billingCsrf);
 paidSessionAssert(str_contains($invoiceDashboardHtml,'/staff/billing/invoices/issue') &&
  str_contains($invoiceDashboardHtml,$plan) &&
  str_contains($invoiceDashboardHtml,$provider) &&
  str_contains($invoiceDashboardHtml,'21000') &&
  !str_contains($invoiceDashboardHtml,$billingSession),
  'only Billing Officer dashboard sees eligible server-priced invoice choices');
 $issuerHtml=$invoiceDashboard->render($issuer,$company,$issuerCsrf);
 paidSessionAssert(!str_contains($issuerHtml,'/staff/billing/invoices/issue'),
  'License Officer cannot see Billing Officer invoice issuance form');
 $invoiceDirectory=new \Hafzatech\Central\CentralStaffVendorInvoiceDirectory(
  $db,$queueAuth);
 $invoiceDirectoryRouter=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
  $queueBoundary,$unusedBilling,true,invoiceDirectory:$invoiceDirectory);
 $directoryHttp=$queueRequest;
 $directoryHttp['REQUEST_URI']='/staff/billing/invoices/recent';
 $list=$invoiceDirectoryRouter->dispatch(
  $directoryHttp,$billingCookie,['customer_id'=>$company],$billingCsrf);
 paidSessionAssert($list['kind']==='private_vendor_invoice_directory_html' &&
  str_contains($list['html'],'/staff/billing/overview') &&
  str_contains($list['html'],'name="staff_csrf"') &&
  str_contains($list['html'],'name="invoice_id"') &&
  str_contains($list['html'],'NOT paid') &&
  !str_contains($list['html'],$billingSession) &&
  !str_contains($list['html'],$provider),
  'recent invoices private list with functional navigation but no bank or session details');
 $hostileDirectory=\Hafzatech\Central\CentralStaffVendorInvoiceDirectoryRenderer::render([
  ['invoice_id'=>$invoice,'invoice_number'=>'<script>alert(1)</script>',
   'status'=>'issued','term_months'=>12,'total_minor'=>'21000',
   'currency'=>'PKR','issued_at'=>'2026-10-19T00:00:00Z']
 ],$company,$billingCsrf);
 paidSessionAssert(!str_contains($hostileDirectory,'<script>') &&
  str_contains($hostileDirectory,'&lt;script&gt;') &&
  str_contains($hostileDirectory,'name="staff_csrf"'),
  'hostile imported invoice references escaped and navigation protected');
 $directoryResponse=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
  ->compose($list);
 paidSessionAssert($directoryResponse['status']===200 &&
  $directoryResponse['cookies']===[] &&
  $directoryResponse['headers']['Cache-Control']==='no-store, private',
  'invoice directory protected HTML with no caching and no cookies');
 paidSessionDeny(fn()=>$invoiceDirectoryRouter->dispatch(
  $directoryHttp,$issuerCookie,['customer_id'=>$company],$issuerCsrf),
  'staff_portal_denied');
 // Billing Officer is intentionally GLOBAL in this synthetic fixture;
 // customer B is authorized, but must never receive A's invoices.
 $foreignDirectory=$invoiceDirectoryRouter->dispatch(
  $directoryHttp,$billingCookie,['customer_id'=>$other],$billingCsrf);
 paidSessionAssert($foreignDirectory['kind']==='private_vendor_invoice_directory_html' &&
  str_contains($foreignDirectory['html'],'No invoices recorded') &&
  !str_contains($foreignDirectory['html'],$invoice),
  'global Billing Officer cannot leak another customer invoice into foreign listing');
 paidSessionDeny(fn()=>$invoiceDirectory->listForCustomer(
  $foreignSession,$company),'staff_invoice_directory_denied');
 paidSessionDeny(fn()=>$invoiceDirectoryRouter->dispatch(
  $directoryHttp,$billingCookie,['customer_id'=>$company,'secret'=>'bank'],
  $billingCsrf),'invalid_invoice_directory_request');
 paidSessionDeny(fn()=>$invoiceDirectoryRouter->dispatch(
  $directoryHttp,$billingCookie,['customer_id'=>$company],str_repeat('0',64)),
  'staff_portal_denied');
 $invoiceResponse=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
  ->compose($invoicePage);
 paidSessionAssert($invoiceResponse['status']===200 &&
  $invoiceResponse['cookies']===[] &&
  $invoiceResponse['headers']['Cache-Control']==='no-store, private',
  'vendor invoice receipt private, no cookies, no caching');
 $again=$invoiceRouter->dispatch($invoiceHttp,
  $billingCookie,$invoiceFields,$billingCsrf);
 paidSessionAssert($again===$invoicePage,
  'browser retry reuses original invoice without posting a second one');
 paidSessionDeny(fn()=>$invoiceRouter->dispatch(
  $invoiceHttp,$issuerCookie,$invoiceFields,$issuerCsrf),
  'staff_portal_denied');
 paidSessionDeny(fn()=>$invoiceRouter->dispatch(
  $invoiceHttp,$billingCookie,$invoiceFields,str_repeat('0',64)),
  'staff_portal_denied');
 paidSessionDeny(fn()=>$invoiceRouter->dispatch(
  $invoiceHttp,$billingCookie,$invoiceFields+['price_minor'=>1],$billingCsrf),
  'invalid_vendor_invoice_request');
 $badInvoiceOrigin=$invoiceHttp;
 $badInvoiceOrigin['HTTP_ORIGIN']='https://fake.example.test';
 paidSessionDeny(fn()=>$invoiceRouter->dispatch(
  $badInvoiceOrigin,$billingCookie,$invoiceFields,$billingCsrf),
  'staff_portal_denied');
 paidSessionDeny(fn()=>\Hafzatech\Central\CentralStaffVendorInvoiceReceiptRenderer::render(
  ['invoice_id'=>paidSessionUuid(),'invoice_number'=>'<script>',
   'currency'=>'USD','total_minor'=>'1','term_months'=>6,'status'=>'issued']),
  'invalid_vendor_invoice_receipt');
}finally{
 putenv('CENTRAL_VENDOR_INVOICE_ISSUANCE_ENABLED=false');
}
$dashboardWire=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
 ->compose($billingDashboard);
paidSessionAssert($dashboardWire['cookies']===[] &&
 $dashboardWire['headers']['Cache-Control']==='no-store, private' &&
 $dashboardWire['status']===200,
 'private role dashboard remains uncached and credential-free');
paidSessionDeny(fn()=>$dashboardRouter->dispatch(
 $dashboardHttp,$foreignCookie,['customer_id'=>$company],$foreignCsrf),
 'staff_portal_denied');
paidSessionDeny(fn()=>$dashboardRouter->dispatch(
 $dashboardHttp,$issuerCookie,['customer_id'=>'invalid'],$issuerCsrf),
 'invalid_customer_lookup');
paidSessionDeny(fn()=>$dashboardRouter->dispatch(
 $dashboardHttp,$issuerCookie,['customer_id'=>$company,'role'=>'billing_officer'],
 $issuerCsrf),'invalid_customer_lookup');
paidSessionDeny(fn()=>$dashboardRouter->dispatch(
 $dashboardHttp,$issuerCookie,['customer_id'=>$company],str_repeat('0',64)),
 'staff_portal_denied');
$wrongDashboardHost=$dashboardHttp;
$wrongDashboardHost['HTTP_HOST']='attacker.example';
paidSessionDeny(fn()=>$dashboardRouter->dispatch(
 $wrongDashboardHost,$issuerCookie,['customer_id'=>$company],$issuerCsrf),
 'staff_portal_denied');
$forgedNameDashboard=$dashboardService->render($issuer,$other);
paidSessionAssert(str_contains($forgedNameDashboard,'Foreign License Session Venue') &&
 !str_contains($forgedNameDashboard,'<h2>Billing Officer</h2>'),
 'global read session renders correct target customer under live grant');

// Separate support staff inbox can read ONLY allowlisted ticket metadata.
// This test uses real MFA sessions and an encrypted-subject PostgreSQL ticket;
// customer subject, reply body and vendor signing keys are never decrypted.
$supportStaff=paidSessionUuid();
$makeUser->execute(['id'=>$supportStaff,
 'email'=>'paid-session-support@example.test',
 'hash'=>password_hash($password,PASSWORD_ARGON2ID),
 'seed'=>bin2hex($seed)]);
$grant->execute(['id'=>paidSessionUuid(),'staff'=>$supportStaff,
 'role'=>'support_agent','scope'=>$company]);
$supportBearer=$sessions->login(
 'paid-session-support@example.test',$password,paidSessionTotp($seed),
 '192.0.2.145');
paidSessionAssert(is_string($supportBearer),'real scoped support agent MFA');
$ticket=paidSessionUuid();
$db->prepare("INSERT INTO vendor_support_tickets
 (id,customer_id,installation_id,client_ticket_id,
  subject_ciphertext,content_sha256,status)
 VALUES(:id,:customer,:installation,:client,decode(:cipher,'hex'),
 :digest,'new')")
 ->execute(['id'=>$ticket,'customer'=>$company,'installation'=>$installation,
 'client'=>paidSessionUuid(),'cipher'=>bin2hex(random_bytes(32)),
 'digest'=>hash('sha256','Encrypted support fixture')]);
$supportReader=new \Hafzatech\Central\CentralStaffSupportTicketQueue(
 $db,$queueAuth);
paidSessionDeny(fn()=>$supportReader->listForCustomer(
 $issuer,$company),'support_scope_denied');
paidSessionDeny(fn()=>$supportReader->listForCustomer(
 $billingSession,$company),'support_scope_denied');
paidSessionDeny(fn()=>$supportReader->listForCustomer(
 $supportBearer,$other),'support_scope_denied');
paidSessionDeny(fn()=>$supportReader->listForCustomer(
 $supportBearer,'invalid'),'invalid_support_queue_request');
paidSessionDeny(fn()=>$supportReader->listForCustomer(
 $supportBearer,$company,0,26),'invalid_support_queue_request');
$ticketRows=$supportReader->listForCustomer($supportBearer,$company);
paidSessionAssert(count($ticketRows)===1 &&
 array_keys($ticketRows[0])===['ticket_number','status','created_at'] &&
 $ticketRows[0]['status']==='new',
 'support staff reads only authorized ticket number, status and creation time');
paidSessionAssert($supportReader->listForCustomer(
 $supportBearer,$company,1)===[],
 'strict support metadata pagination');
$ticketHtml=\Hafzatech\Central\CentralStaffSupportQueueHtmlRenderer::render(
 $ticketRows);
paidSessionAssert(str_contains($ticketHtml,'HAF-') &&
 str_contains($ticketHtml,'new') &&
 !str_contains($ticketHtml,$ticket) &&
 !str_contains($ticketHtml,$supportBearer) &&
 !str_contains($ticketHtml,$company),
 'private support HTML omits ticket UUID, customer ID, secrets and ciphertext');
$hostile=\Hafzatech\Central\CentralStaffSupportQueueHtmlRenderer::render([[
 'ticket_number'=>1,
 'status'=>'<img src=x onerror=alert(1)>',
 'created_at'=>'<script>bad()</script>'
]]);
paidSessionAssert(str_contains($hostile,'&lt;img') &&
 str_contains($hostile,'&lt;script&gt;') &&
 !str_contains($hostile,'<img') &&
 !str_contains($hostile,'<script>'),
 'hostile support metadata is escaped before private display');
// Support Agent's metadata-only page through exact, default-disabled
// portal routing: live role, customer scope, trusted origin and session CSRF.
$supportCsrf=$queueCsrfTokens->issue($supportBearer);
paidSessionAssert(is_string($supportCsrf),'support agent live CSRF proof');
$supportRouter=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
 $queueBoundary,$unusedBilling,true,$queue,$customerReader,
 $dashboardService,$supportReader);
$supportHttp=$queueRequest;
$supportHttp['REQUEST_URI']='/staff/support/tickets';
$supportCookie=[
 \Hafzatech\Central\CentralStaffHttpPolicy::COOKIE_NAME=>$supportBearer
];
$supportPage=$supportRouter->dispatch(
 $supportHttp,$supportCookie,['customer_id'=>$company],$supportCsrf);
paidSessionAssert($supportPage['kind']==='private_support_html' &&
 str_contains($supportPage['html'],'HAF-') &&
 !str_contains($supportPage['html'],$ticket) &&
 !str_contains($supportPage['html'],$supportBearer),
 'support portal reveals only permitted ticket metadata');
$supportWire=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
 ->compose($supportPage);
paidSessionAssert($supportWire['cookies']===[] &&
 $supportWire['headers']['Cache-Control']==='no-store, private',
 'support portal response private and cache-disabled');
$agentDashboardHttp=$queueRequest;
$agentDashboardHttp['REQUEST_URI']='/staff/dashboard';
$agentDash=$supportRouter->dispatch(
 $agentDashboardHttp,$supportCookie,['customer_id'=>$company],$supportCsrf);
paidSessionAssert(str_contains($agentDash['html'],'<h2>Support Agent</h2>') &&
 str_contains($agentDash['html'],'action="/staff/support/tickets"') &&
 !str_contains($agentDash['html'],'action="/staff/billing/overview"') &&
 !str_contains($agentDash['html'],'<h2>License Officer</h2>') &&
 !str_contains($agentDash['html'],'<h2>Billing Officer</h2>'),
 'support-only session has no billing or signing dashboard module');
$agentLookupHttp=$queueRequest;
$agentLookupHttp['REQUEST_URI']='/staff/customers/lookup';
$agentLookup=$supportRouter->dispatch(
 $agentLookupHttp,$supportCookie,['customer_id'=>$company],$supportCsrf);
paidSessionAssert(str_contains($agentLookup['html'],'License Session Venue'),
 'support agent may read its own registered business identity only');
paidSessionDeny(fn()=>$supportRouter->dispatch(
 $supportHttp,$billingCookie,['customer_id'=>$company],$billingCsrf),
 'staff_portal_denied');
paidSessionDeny(fn()=>$supportRouter->dispatch(
 $supportHttp,$supportCookie,['customer_id'=>$other],$supportCsrf),
 'staff_portal_denied');
paidSessionDeny(fn()=>$supportRouter->dispatch(
 $supportHttp,$supportCookie,
 ['customer_id'=>$company,'ticket_id'=>$ticket],$supportCsrf),
 'invalid_support_queue_request');
paidSessionDeny(fn()=>$supportRouter->dispatch(
 $supportHttp,$supportCookie,['customer_id'=>$company],str_repeat('0',64)),
 'staff_portal_denied');
$supportWrongOrigin=$supportHttp;
$supportWrongOrigin['HTTP_ORIGIN']='https://evil.example.test';
paidSessionDeny(fn()=>$supportRouter->dispatch(
 $supportWrongOrigin,$supportCookie,['customer_id'=>$company],$supportCsrf),
 'staff_portal_denied');
// True authenticated support staff may reply only under independent
// operator release gates; caller-supplied actor/MFA can never impersonate.
$testVault=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$sealSupport=static function(string $body)use($testVault):string{
 $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
 return $nonce.sodium_crypto_secretbox($body,$nonce,$testVault);
};
$replyService=new \Hafzatech\Central\SupportAgentReplyService(
 $db,$access,$sealSupport);
$replyOff=new \Hafzatech\Central\CentralStaffSessionSupportReply(
 $db,$sessions,$queueAuth,$replyService);
$replyOn=new \Hafzatech\Central\CentralStaffSessionSupportReply(
 $db,$sessions,$queueAuth,$replyService,true);
$replyId=paidSessionUuid();
$replyBody='Synthetic test-only staff response. No real client message.';
putenv('CENTRAL_STAFF_SUPPORT_REPLY_HTTP_ENABLED=false');
putenv('CENTRAL_SUPPORT_STAFF_REPLY_ENABLED=false');
paidSessionDeny(fn()=>$replyOff->reply(
 $supportBearer,$company,$ticket,$replyId,$replyBody),
 'staff_support_reply_not_enabled');
paidSessionDeny(fn()=>$replyOn->reply(
 $supportBearer,$company,$ticket,$replyId,$replyBody),
 'staff_support_reply_not_enabled');
putenv('CENTRAL_STAFF_SUPPORT_REPLY_HTTP_ENABLED=true');
putenv('CENTRAL_SUPPORT_STAFF_REPLY_ENABLED=true');
try {
 paidSessionDeny(fn()=>$replyOff->reply(
  $supportBearer,$company,$ticket,$replyId,$replyBody),
  'staff_support_reply_not_enabled');
 paidSessionDeny(fn()=>$replyOn->reply(
  $issuer,$company,$ticket,$replyId,$replyBody),'support_scope_denied');
 paidSessionDeny(fn()=>$replyOn->reply(
  $supportBearer,$other,$ticket,$replyId,$replyBody),'support_scope_denied');
 paidSessionDeny(fn()=>$replyOn->reply(
  $supportBearer,$company,'not-a-uuid',$replyId,$replyBody),
  'invalid_support_reply_request');
 paidSessionDeny(fn()=>$replyOn->reply(
  $supportBearer,$company,$ticket,$replyId,'x'),
  'invalid_support_reply_request');
 $replyResult=$replyOn->reply(
  $supportBearer,$company,$ticket,$replyId,$replyBody);
 paidSessionAssert($replyResult['status']==='waiting_for_client' &&
  $replyOn->reply($supportBearer,$company,$ticket,$replyId,$replyBody)===$replyResult,
  'vendor support reply written once and replay is idempotent');
 $replyCount=$db->prepare("SELECT count(*) FROM vendor_support_messages
  WHERE ticket_id=:ticket AND direction='staff'");
 $replyCount->execute(['ticket'=>$ticket]);
 paidSessionAssert((int)$replyCount->fetchColumn()===1,
  'one durable encrypted staff message posted');
 $auditCount=$db->prepare("SELECT count(*) FROM vendor_support_reply_audit
  WHERE ticket_id=:ticket");
 $auditCount->execute(['ticket'=>$ticket]);
 paidSessionAssert((int)$auditCount->fetchColumn()===1,
  'one independently immutable support reply journal');
 $receiptHtml=\Hafzatech\Central\CentralStaffSessionSupportReply::html(
  $replyResult);
 paidSessionAssert(str_contains($receiptHtml,'waiting_for_client') &&
  !str_contains($receiptHtml,$replyBody) &&
  !str_contains($receiptHtml,$supportBearer) &&
  !str_contains($receiptHtml,$ticket),
  'private reply receipt has no plaintext reply, bearer or ticket identifiers');
 $replyHttp=$queueRequest;
 $replyHttp['REQUEST_URI']='/staff/support/reply';
 $replyRouter=new \Hafzatech\Central\CentralStaffPortalHttpRouter(
  $queueBoundary,$unusedBilling,true,$queue,$customerReader,
  $dashboardService,$supportReader,null,null,null,$replyOn);
 $replyFields=['customer_id'=>$company,'ticket_id'=>$ticket,
  'message_id'=>$replyId,'body'=>$replyBody];
 $replyPage=$replyRouter->dispatch(
  $replyHttp,$supportCookie,$replyFields,$supportCsrf);
 paidSessionAssert($replyPage['kind']==='private_support_reply_html' &&
  !str_contains($replyPage['html'],$replyBody),
  'protected same-origin router returns safe encrypted reply receipt');
 $replyWire=(new \Hafzatech\Central\CentralStaffPortalPrivateResponse(true))
  ->compose($replyPage);
 paidSessionAssert($replyWire['cookies']===[] &&
  $replyWire['headers']['Cache-Control']==='no-store, private',
  'reply receipt cannot issue bearer or be cached');
 paidSessionDeny(fn()=>$replyRouter->dispatch(
  $replyHttp,$billingCookie,$replyFields,$billingCsrf),'staff_portal_denied');
 paidSessionDeny(fn()=>$replyRouter->dispatch(
  $replyHttp,$supportCookie,$replyFields+['actor'=>$issuer],$supportCsrf),
  'invalid_support_reply_request');
 paidSessionDeny(fn()=>$replyRouter->dispatch(
  $replyHttp,$supportCookie,$replyFields,str_repeat('0',64)),
  'staff_portal_denied');
 $wrongReplyOrigin=$replyHttp;
 $wrongReplyOrigin['HTTP_ORIGIN']='https://evil.example';
 paidSessionDeny(fn()=>$replyRouter->dispatch(
  $wrongReplyOrigin,$supportCookie,$replyFields,$supportCsrf),
  'staff_portal_denied');
 paidSessionDeny(fn()=>$replyRouter->dispatch(
  $replyHttp,$supportCookie,
  ['customer_id'=>$other,'ticket_id'=>$ticket,
   'message_id'=>paidSessionUuid(),'body'=>$replyBody],$supportCsrf),
  'staff_portal_denied');
} finally {
 putenv('CENTRAL_STAFF_SUPPORT_REPLY_HTTP_ENABLED=false');
 putenv('CENTRAL_SUPPORT_STAFF_REPLY_ENABLED=false');
}
$db->prepare("UPDATE central_staff_users SET status='suspended' WHERE id=:staff")
 ->execute(['staff'=>$supportStaff]);
paidSessionDeny(fn()=>$supportReader->listForCustomer(
 $supportBearer,$company),'support_scope_denied');
paidSessionDeny(fn()=>$supportRouter->dispatch(
 $supportHttp,$supportCookie,['customer_id'=>$company],$supportCsrf),
 'staff_portal_denied');
$manifest=new CentralStaffSignedGrantManifest($db,$sessions,
    new CentralStaffSessionAuthorization($sessions,$access),
    new PaidInvoiceEligibility($db));
paidSessionDeny(fn()=>$manifest->forRevision(str_repeat('0',64),$prepared['revision_id']),
    'staff_session_required');
paidSessionDeny(fn()=>$manifest->forRevision($billingSession,$prepared['revision_id']),
    'license_officer_required');
paidSessionDeny(fn()=>$manifest->forRevision($foreignSession,$prepared['revision_id']),
    'license_officer_required');
paidSessionDeny(fn()=>$manifest->forRevision($issuer,'invalid'),
    'invalid_license_request');
paidSessionDeny(fn()=>$manifest->forRevision($issuer,paidSessionUuid()),
    'signed_grant_not_eligible');
paidSessionAssert($manifest->forRevision($issuer,$prepared['revision_id'])===$claim,
    'exact paid grant manifest derives the signed claim from immutable invoice snapshot');
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
paidSessionDeny(fn()=>$manifest->forRevision($issuer,$prepared['revision_id']),
    'signed_grant_not_eligible');
paidSessionAssert($queue->listPending($issuer,$company)===[],
    'already signed revision leaves vendor pending queue');
$signatureCount=$db->prepare('SELECT count(*) FROM central_license_signature_events
    WHERE revision_id=:rev AND authorized_officer_id=:officer');
$signatureCount->execute(['rev'=>$prepared['revision_id'],'officer'=>$officer]);
paidSessionAssert((int)$signatureCount->fetchColumn()===1,
    'exactly one immutable signing audit tied to authenticated vendor officer');
// Signed-grant delivery path: the finalization output must be retrievable only
// by a fresh Ed25519 installation proof and must equal the signed envelope.
$deliveryNonce=paidSessionUuid();$deliveryTime=gmdate('Y-m-d\TH:i:s\Z');
$deliveryMessage=implode("\n",['HAFZATECH-INSTALL-CHECK-V1',$installation,
    $company,$deliveryTime,$deliveryNonce,'0']);
$deliveryRequest=['installation_id'=>$installation,'customer_id'=>$company,
    'request_time'=>$deliveryTime,'nonce'=>InstallationRegistry::base64Url(random_bytes(32)),
    'known_revision'=>0,
    'signature'=>InstallationRegistry::base64Url(sodium_crypto_sign_detached(
        $deliveryMessage,sodium_crypto_sign_secretkey($installationKeys)))];
$deliveryRequest['nonce']=InstallationRegistry::base64Url(random_bytes(32));
$deliveryMessage=implode("\n",['HAFZATECH-INSTALL-CHECK-V1',$installation,
    $company,$deliveryTime,$deliveryRequest['nonce'],'0']);
$deliveryRequest['signature']=InstallationRegistry::base64Url(
    sodium_crypto_sign_detached($deliveryMessage,
        sodium_crypto_sign_secretkey($installationKeys)));
$unseal=static function(string $sealed)use($vault):string|false{
    $nonce=substr($sealed,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return sodium_crypto_secretbox_open(
        substr($sealed,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$vault);
};
$delivery=new \Hafzatech\Central\SignedGrantDeliveryService(
    $db,new \Hafzatech\Central\InstallationStatusService($db),$unseal,
    ['session-test-key'=>$public]);
putenv('CENTRAL_GRANT_DELIVERY_ENABLED=true');
try {
    $delivered=$delivery->fetch($deliveryRequest);
    paidSessionAssert($delivered['status']==='signed_revision_pending_delivery' &&
        $delivered['revision']===1 && is_array($delivered['signed_grant']) &&
        $delivered['signed_grant']===$envelope,
        'authenticated installation receives exactly the finalized signed grant');
} finally {
    putenv('CENTRAL_GRANT_DELIVERY_ENABLED=false');
}
paidSessionDeny(fn()=>$delivery->fetch($deliveryRequest),'grant_delivery_not_enabled');
// A revoked License Officer must also lose the pending queue capability.
$db->prepare("UPDATE central_staff_role_grants SET revoked_at=now()
    WHERE staff_id=:id AND role='license_officer'")
 ->execute(['id'=>$officer]);
paidSessionDeny(fn()=>$service->prepare($issuer,$invoice,$installation,$now),
    'staff_session_required');
paidSessionDeny(fn()=>$queue->listPending($issuer,$company),
    'license_officer_required');
paidSessionAssert($sessions->staffFor($issuer)===null,
    'revoked officer role invalidates previously valid bearer');
echo "PASS: vendor-session-only paid license preparation, independent payment settlement, customer scope, idempotency and live role revocation\n";
