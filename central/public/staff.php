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
    echo $body;
    exit;
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
if ($method !== 'POST' || ! is_string($path) || ! str_starts_with($path, '/staff/')) {
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
    error_log('Central staff portal request denied: '.get_class($error));
    centralStaffReply(403, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Denied</title></head><body><main><h1>Staff request denied</h1></main></body></html>');
}
