<?php
declare(strict_types=1);

use Hafzatech\Central\InstallationRegistry;
use Hafzatech\Central\RegistrationError;
use Hafzatech\Central\InstallationStatusService;

require dirname(__DIR__).'/src/InstallationRegistry.php';
require dirname(__DIR__).'/src/InstallationStatusService.php';
require dirname(__DIR__).'/src/CentralInstallationApiHttpPolicy.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit;
}

// No routes available until central deployment operators explicitly enable them.
// TLS must be terminated with HTTPS=on or SERVER_PORT=443 by trusted server config;
// untrusted X-Forwarded-Proto headers are intentionally ignored.
if (getenv('CENTRAL_API_ENABLED') !== 'true') reply(503, ['api_version' => 'v1', 'error' => 'service_not_enabled']);
if (!\Hafzatech\Central\CentralInstallationApiHttpPolicy::trustedHost($_SERVER)) {
    reply(403, ['api_version'=>'v1','error'=>'trusted_https_host_required']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['api_version' => 'v1', 'error' => 'method_not_allowed']);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!in_array($path, ['/api/v1/installations/challenge', '/api/v1/installations/register', '/api/v1/installations/status'], true)) {
    reply(404, ['api_version' => 'v1', 'error' => 'not_found']);
}
if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    reply(415, ['api_version' => 'v1', 'error' => 'json_required']);
}
$raw = file_get_contents('php://input', false, null, 0, 8193);
if ($raw === false || strlen($raw) > 8192) reply(413, ['api_version' => 'v1', 'error' => 'request_too_large']);
try {
    $request = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request) || array_is_list($request)) throw new RegistrationError('invalid_request', 422);
    $dsn = getenv('CENTRAL_DB_DSN');
    $username = getenv('CENTRAL_DB_USERNAME');
    $password = getenv('CENTRAL_DB_PASSWORD');
    if (!$dsn || !$username || $password === false) throw new RuntimeException('central database not configured');
    $db = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $registry = new InstallationRegistry($db);
    if ($path === '/api/v1/installations/challenge') {
        if (!is_string($request['enrollment_id'] ?? null) || !is_string($request['enrollment_token'] ?? null)) {
            throw new RegistrationError('invalid_request', 422);
        }
        reply(200, $registry->challenge($request['enrollment_id'], $request['enrollment_token']));
    }
    if ($path === '/api/v1/installations/status') {
        reply(200, (new InstallationStatusService($db))->check($request));
    }
    reply(201, $registry->register($request));
} catch (RegistrationError $e) {
    reply($e->httpStatus, ['api_version' => 'v1', 'error' => $e->errorCode]);
} catch (Throwable $e) {
    // Keep database and signing details out of externally-visible responses.
    error_log('Central installation API failure: '.get_class($e));
    reply(500, ['api_version' => 'v1', 'error' => 'internal_error']);
}
