<?php
declare(strict_types=1);

use Hafzatech\Central\CentralStaffPortalOperatorWiring;
use Hafzatech\Central\RegistrationError;

foreach ([
    'InstallationRegistry', 'StaffRolePermissions', 'CentralStaffAccess',
    'CentralStaffPasswordVerifier', 'CentralStaffTotpVerifier',
    'CentralStaffMfaChallenge', 'CentralStaffCredentialVerifier',
    'CentralStaffLoginRateLimiter', 'CentralStaffSessionService',
    'CentralStaffSessionAuthorization', 'CentralStaffCsrfTokenService',
    'CentralStaffHttpPolicy', 'CentralStaffProtectedHttpBoundary',
    'CentralStaffPaymentEvidenceQueue', 'CentralStaffPaymentEvidenceReviewService',
    'CentralStaffInvoicePaymentOverview', 'CentralStaffPaymentEvidenceHttpAdapter',
    'CentralStaffBillingDashboardRenderer', 'CentralStaffCustomerLookup',
    'CentralStaffPortalDashboard', 'CentralStaffSupportTicketQueue',
    'CentralStaffSupportQueueHtmlRenderer', 'CentralStaffSessionAuditReader',
    'CentralStaffAccountActionReader', 'CentralStaffProvisionAuditReader',
    'CentralStaffPortalAuditHistoryPage', 'CentralStaffLicenseSigningQueue',
    'CentralStaffLicenseQueueHtmlRenderer', 'CentralStaffAccountDirectory',
    'CentralStaffRoleGrantDirectory', 'CentralStaffProvisioningService',
    'CentralStaffActivationReadiness', 'SupportAgentReplyService',
    'CentralStaffSessionSupportReply', 'CentralStaffOwnPasswordChange',
    'CentralStaffPortalOwnPassword', 'CentralStaffPortalTeamDashboard',
    'CentralStaffPortalAuditorDashboard', 'CentralStaffRoleReviewQueue',
    'CentralStaffRoleRequestService', 'CentralStaffRoleDecisionService',
    'CentralStaffVendorInvoiceIssuer', 'CentralStaffVendorInvoiceReceiptRenderer',
    'CentralStaffVendorInvoiceDirectory', 'CentralStaffVendorInvoiceDirectoryRenderer',
    'CentralStaffVendorPlanPublisher', 'CentralStaffVendorPlanCatalogRenderer',
    'CentralStaffVendorPlanCatalogReader', 'CentralStaffPortalHome',
    'CentralStaffCustomerDirectory', 'CentralStaffCustomerDirectoryRenderer',
    'CentralStaffPortalHttpRouter', 'CentralStaffPortalPrivateResponse',
    'CentralStaffPortalFormRequest', 'CentralStaffPortalOperatorKernel',
    'CentralStaffPortalResponseEmitter', 'CentralStaffPortalOperatorApp',
    'CentralStaffPortalOperatorWiring',
] as $class) {
    require dirname(__DIR__).'/src/'.$class.'.php';
}

function journeyAssert(bool $ok, string $why): void
{
    if (! $ok) throw new RuntimeException('Central portal journey: '.$why);
}
function journeyReject(callable $fn, string $error): void
{
    try { $fn(); } catch (RegistrationError $e) {
        journeyAssert($e->errorCode === $error,
            'expected '.$error.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Central portal journey: expected '.$error);
}
function journeyId(): string
{
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64);
    $b[8] = chr((ord($b[8]) & 63) | 128); $h = bin2hex($b);
    return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'
        .substr($h, 16, 4).'-'.substr($h, 20);
}
function journeyOtp(string $secret): string
{
    $step = intdiv(time(), 30);
    $bits = pack('N2', intdiv($step, 4294967296), $step % 4294967296);
    $mac = hash_hmac('sha1', $bits, $secret, true);
    $at = ord($mac[19]) & 15;
    $int = unpack('N', substr($mac, $at, 4))[1] & 0x7fffffff;
    return str_pad((string) ($int % 1000000), 6, '0', STR_PAD_LEFT);
}

$dsn = getenv('CENTRAL_DB_DSN');
journeyAssert(is_string($dsn) && str_contains($dsn, 'hafzatech_central_test'),
    'isolated central PostgreSQL test DB required');
$db = new PDO($dsn, getenv('CENTRAL_DB_USERNAME'), getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$secret = '12345678901234567890';
$email = 'vendor-portal-journey-'.bin2hex(random_bytes(4)).'@example.test';
$id = journeyId();
$db->prepare("INSERT INTO central_staff_users
    (id,email,display_name,password_hash,mfa_secret_ciphertext,mfa_enrolled_at,status)
    VALUES(:id,:email,'Synthetic Portal Journey',:hash,decode(:sealed,'hex'),now(),'active')")
    ->execute(['id' => $id, 'email' => $email,
        'hash' => password_hash('synthetic-vendor-pass', PASSWORD_DEFAULT),
        'sealed' => bin2hex($secret)]);
$db->prepare("INSERT INTO central_staff_role_grants(id,staff_id,role,reason)
    VALUES(:grant,:id,'super_administrator','Synthetic journey operator role')")
    ->execute(['grant' => journeyId(), 'id' => $id]);

$headers = []; $cookies = []; $bodies = [];
$writeHeader = static function (string $header) use (&$headers): void { $headers[] = $header; };
$writeCookie = static function (string $name, string $value, array $options) use (&$cookies): bool {
    $cookies[] = [$name, $value, $options]; return true;
};
$writeBody = static function (string $body) use (&$bodies): void { $bodies[] = $body; };
$sent = static fn (): bool => false;
$decrypt = static fn (string $sealed): string => $sealed;
$encrypt = static fn (string $body): string => random_bytes(24).$body;

putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=true');
putenv('CENTRAL_STAFF_PORTAL_ENABLED=true');
putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=true');
$app = CentralStaffPortalOperatorWiring::build(
    $db, str_repeat('j', 32), $decrypt, $encrypt,
    $writeHeader, $writeCookie, $writeBody, $sent, true,
);
$server = [
    'HTTPS' => 'on', 'SERVER_PORT' => '443',
    'HTTP_HOST' => 'lichall.hafzatech.com',
    'HTTP_ORIGIN' => 'https://lichall.hafzatech.com',
    'HTTP_SEC_FETCH_SITE' => 'same-origin', 'REQUEST_METHOD' => 'POST',
    'REMOTE_ADDR' => '192.0.2.220',
    'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
];
$login = static fn (string $otp): string => http_build_query([
    'email' => $email, 'password' => 'synthetic-vendor-pass', 'totp' => $otp,
]);

try {
    $bad = $server; $bad['REQUEST_URI'] = '/staff/session/login';
    journeyReject(fn () => $app->serve($bad, [], $login('000000')), 'staff_login_denied');
    journeyAssert($cookies === [] && $bodies === [], 'failed MFA wrote no cookie/body');

    $loginServer = $server; $loginServer['REQUEST_URI'] = '/staff/session/login';
    $app->serve($loginServer, [], $login(journeyOtp($secret)));
    journeyAssert(count($cookies) === 1 && strlen($cookies[0][1]) === 64,
        'secure staff cookie missing');
    journeyAssert($cookies[0][0] === '__Host-HafzatechStaff'
        && $cookies[0][2]['secure'] === true && $cookies[0][2]['httponly'] === true
        && $cookies[0][2]['samesite'] === 'Strict', 'cookie flags invalid');
    $body = end($bodies);
    journeyAssert(is_string($body) && str_contains($body, 'Open Central Control Center'),
        'login dashboard handoff missing');
    journeyAssert(! str_contains($body, $cookies[0][1]), 'session bearer leaked into HTML');
    preg_match('/name="staff_csrf" value="([a-f0-9]{64})"/', $body, $m);
    $csrf = $m[1] ?? '';
    journeyAssert($csrf !== '', 'CSRF proof missing');
    $cookieBag = ['__Host-HafzatechStaff' => $cookies[0][1]];

    $headers = []; $bodies = []; $cookies = [];
    $home = $server; $home['REQUEST_URI'] = '/staff/home';
    $app->serve($home, $cookieBag, http_build_query(['staff_csrf' => $csrf]));
    journeyAssert(str_contains(end($bodies), 'Private HAFZATECH staff home'),
        'authenticated staff home not rendered');

    $headers = []; $bodies = [];
    $customers = $server; $customers['REQUEST_URI'] = '/staff/customers/directory';
    $app->serve($customers, $cookieBag, http_build_query([
        'staff_csrf' => $csrf, 'search' => 'synthetic',
    ]));
    journeyAssert(str_contains(end($bodies), 'Private HAFZATECH customer directory'),
        'authorized customer directory not rendered');

    $headers = []; $bodies = [];
    $logout = $server; $logout['REQUEST_URI'] = '/staff/session/logout';
    $app->serve($logout, $cookieBag, http_build_query(['staff_csrf' => $csrf]));
    journeyAssert(str_contains(end($bodies), 'Signed out'), 'logout page missing');
    journeyAssert($cookies[0][1] === '', 'logout did not clear cookie');

    $after = $server; $after['REQUEST_URI'] = '/staff/home';
    journeyReject(fn () => $app->serve($after, $cookieBag,
        http_build_query(['staff_csrf' => $csrf])), 'staff_portal_denied');
} finally {
    putenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED=false');
    putenv('CENTRAL_STAFF_PORTAL_ENABLED=false');
    putenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED=false');
}

echo "PASS: authenticated central staff portal login, secure cookie/CSRF, home, customer directory, logout and revoked-session denial\n";
