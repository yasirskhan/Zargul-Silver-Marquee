<?php
declare(strict_types=1);

/**
 * HAFZATECH central staff portal front controller.
 *
 * DEFAULT OFF. This file is shipped in central/public so an approved operator
 * vhost can expose the existing, separately gated staff portal composition.
 * It never contains signing keys, KMS secrets or customer credentials; the
 * operator supplies an external bootstrap file through
 * CENTRAL_STAFF_KMS_BOOTSTRAP that returns the two required closures.
 */

use Hafzatech\Central\CentralStaffHttpPolicy;
use Hafzatech\Central\CentralStaffPortalOperatorWiring;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Hafzatech\\Central\\';
    if (! str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z0-9]+$/D', $name) !== 1) return;
    $path = dirname(__DIR__).'/src/'.$name.'.php';
    if (is_file($path)) require $path;
});

function centralStaffReply(int $status, string $body): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    echo str_starts_with(ltrim($body), '<!doctype') ? $body : centralStaffChrome($body);
    exit;
}

function centralStaffChrome(string $inner): string
{
    $css = <<<'CSS'
:root{--f:#17372e;--g:#caa56b;--c:#fcfaf5;--line:#e3e7df}
*{box-sizing:border-box}body{margin:0;background:var(--c);color:var(--f);font-family:'DM Sans',system-ui,sans-serif;line-height:1.55}
a{color:#8a6a3e}
.central-top{background:linear-gradient(115deg,#10271f,#17372e);color:#fff;padding:20px clamp(18px,5vw,54px);display:flex;justify-content:space-between;align-items:center;gap:16px}
.brand{font-family:Georgia,'Times New Roman',serif;font-size:1.55rem;letter-spacing:.06em;color:#fff;text-decoration:none}.brand b{color:var(--g);font-size:.62rem;letter-spacing:.22em;display:block;font-family:'DM Sans',sans-serif}
.central-wrap{max-width:1180px;margin:0 auto;padding:28px clamp(16px,4vw,42px)}
h1{font-family:Georgia,'Times New Roman',serif;font-size:clamp(2rem,4vw,3.2rem);margin:0 0 8px;color:var(--f)}
h2{font-family:Georgia,'Times New Roman',serif;font-size:1.7rem;margin:0 0 8px;color:var(--f)}
section[aria-label]{background:#fff;border:1px solid var(--line);border-radius:5px;padding:20px;margin:16px 0;box-shadow:0 8px 30px #17372e0a}
nav[aria-label="Authorized staff navigation"]{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin:22px 0}
nav[aria-label="Authorized staff navigation"] form,nav[aria-label="Authorized staff navigation"] section{margin:0}
button{background:var(--f);color:#fff;border:0;border-radius:4px;padding:10px 14px;font:inherit;font-weight:600;cursor:pointer}
button:hover{background:#234b3d}input,select,textarea{border:1px solid #ccd5cd;border-radius:4px;padding:9px;font:inherit;width:100%;max-width:420px;background:#fff}
label{display:block;margin:10px 0;font-weight:600;font-size:.86rem}table{width:100%;border-collapse:collapse;font-size:.86rem}th,td{text-align:left;padding:10px;border-bottom:1px solid var(--line)}th{font-size:.68rem;letter-spacing:.13em;text-transform:uppercase;color:#657369}
.central-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:14px 0}.metric-card{background:linear-gradient(160deg,#fff,#f7f4ec);border:1px solid var(--line);border-radius:5px;padding:16px}.metric-card strong{display:block;font-family:Georgia,serif;font-size:2rem;color:#17372e;line-height:1}.metric-card span{font-size:.74rem;color:#657369}.badge,.status{display:inline-block;background:#f5f1e8;border:1px solid #e2d4ba;color:#6b512f;border-radius:999px;padding:2px 9px;font-size:.7rem;font-weight:700}
footer.central-foot{color:#657369;font-size:.75rem;padding:26px;text-align:center}
@media(max-width:640px){.central-wrap{padding:18px 14px}nav[aria-label="Authorized staff navigation"]{grid-template-columns:1fr}section[aria-label]{padding:15px}}
CSS;
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<title>HAFZATECH Central</title><style>'.$css.'</style></head><body>'
        .'<header class="central-top"><a class="brand" href="/staff">✦ HAFZATECH<b>CENTRAL CONTROL CENTER</b></a>'
        .'<span style="font-size:.7rem;letter-spacing:.16em;color:#caa56b">VENDOR ONLY</span></header>'
        .'<main class="central-wrap">'.$inner.'</main>'
        .'<footer class="central-foot">Separate vendor security boundary. No production signing or settlement is available from this interface.</footer>'
        .'</body></html>';
}

function centralStaffLoginPage(string $error = ''): string
{
    $message = $error !== '' ? '<p role="alert">'.htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>' : '';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width, initial-scale=1">'
        .'<title>HAFZATECH staff sign in</title>'
        .'<style>body{font-family:system-ui,sans-serif;max-width:28rem;margin:4rem auto;padding:1rem}'
        .'label{display:block;margin:.75rem 0}input{width:100%;padding:.7rem}button{padding:.8rem 1.2rem}'
        .'</style></head><body><main><h1>HAFZATECH staff</h1>'
        .'<p>Separate vendor-only access. VenueDesk owner/staff accounts cannot sign in here.</p>'
        .$message
        .'<form method="post" action="/staff/session/login">'
        .'<label>Staff email<input type="email" name="email" required maxlength="254" autocomplete="username"></label>'
        .'<label>Password<input type="password" name="password" required maxlength="1024" autocomplete="current-password"></label>'
        .'<label>Authenticator code<input type="text" name="totp" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"></label>'
        .'<button type="submit">Sign in</button></form></main></body></html>';
}

if (getenv('CENTRAL_STAFF_PUBLIC_PORTAL_ENABLED') !== 'true') {
    centralStaffReply(503, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Unavailable</title></head><body><main><h1>Central staff portal is not enabled</h1></main></body></html>');
}
$server = $_SERVER;
if (! CentralStaffHttpPolicy::secureHost($server)) {
    centralStaffReply(403, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Denied</title></head><body><main><h1>Trusted HTTPS HAFZATECH host required</h1></main></body></html>');
}
$path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET' && $path === '/staff/login') {
    centralStaffReply(200, centralStaffLoginPage());
}
if ($method === 'GET' && $path === '/staff') {
    header('Location: /staff/login', true, 303);
    exit;
}
$readPath = $method === 'GET' && ($path === '/staff/home'
    || str_starts_with((string) $path, '/staff/customers/'));
if (($method !== 'POST' && ! $readPath) || ! is_string($path) || ! str_starts_with($path, '/staff/')) {
    centralStaffReply(405, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Method denied</title></head><body><main><h1>Method not allowed</h1></main></body></html>');
}

$bootstrapPath = getenv('CENTRAL_STAFF_KMS_BOOTSTRAP');
if (! is_string($bootstrapPath) || ! is_file($bootstrapPath) || ! is_readable($bootstrapPath)) {
    centralStaffReply(503, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Unavailable</title></head><body><main><h1>Staff KMS bootstrap is unavailable</h1></main></body></html>');
}
$bootstrap = require $bootstrapPath;
if (! is_array($bootstrap)
    || ! (($bootstrap['decrypt_totp'] ?? null) instanceof Closure)
    || ! (($bootstrap['encrypt_support_reply'] ?? null) instanceof Closure)) {
    centralStaffReply(503, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Unavailable</title></head><body><main><h1>Staff KMS bootstrap is invalid</h1></main></body></html>');
}

$dsn = getenv('CENTRAL_DB_DSN');
$username = getenv('CENTRAL_DB_USERNAME');
$password = getenv('CENTRAL_DB_PASSWORD');
$throttleKey = getenv('CENTRAL_STAFF_THROTTLE_HMAC_KEY');
if (! is_string($dsn) || ! str_starts_with($dsn, 'pgsql:')
    || ! is_string($username) || ! is_string($password)
    || ! is_string($throttleKey) || strlen($throttleKey) < 32) {
    centralStaffReply(503, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Unavailable</title></head><body><main><h1>Central staff configuration is unavailable</h1></main></body></html>');
}
$db = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$writeHeader = static function (string $header): void { header($header); };
$writeCookie = static function (string $name, string $value, array $options): bool {
    return setcookie($name, $value, $options);
};
$writeBody = static function (string $body): void { echo $body; };
$headersSent = static fn (): bool => headers_sent();
try {
    $app = CentralStaffPortalOperatorWiring::build(
        $db, $throttleKey,
        $bootstrap['decrypt_totp'], $bootstrap['encrypt_support_reply'],
        $writeHeader, $writeCookie, $writeBody, $headersSent, true,
    );
    $app->serve($server, $_COOKIE, (string) file_get_contents('php://input'));
} catch (Throwable $error) {
    $reason = $error instanceof \Hafzatech\Central\RegistrationError
        ? $error->errorCode : get_class($error);
    error_log('Central staff portal request denied: '.$reason);
    centralStaffReply(403, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Denied</title></head><body><main><h1>Staff request denied</h1></main></body></html>');
}
