<?php
declare(strict_types=1);

// LOCAL TEST-ONLY status endpoint. It verifies the installation proof against
// the isolated VenueDesk test DB and signs a synthetic receipt with an
// ephemeral test key. It is never served from production central.
$root = dirname(__DIR__, 2);
$privateFile = $root.'/backend/storage/app/browser-e2e-license-private.key';
if (!is_file($privateFile) || getenv('CENTRAL_LOCAL_STATUS_TEST') !== 'true') {
    http_response_code(503);
    echo json_encode(['error' => 'not_enabled']);
    exit;
}
$body = json_decode(file_get_contents('php://input') ?: '{}', true, 16, JSON_THROW_ON_ERROR);
foreach (['installation_id','customer_id','request_time','nonce','known_revision','signature'] as $field) {
    if (!isset($body[$field])) { http_response_code(422); echo '{}'; exit; }
}
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$database = getenv('DB_DATABASE') ?: 'venue_test';
$username = getenv('DB_USERNAME') ?: 'venue';
$password = getenv('DB_PASSWORD');
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);
$db = new PDO($dsn, $username, is_string($password) ? $password : '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $db->prepare('SELECT ed25519_public_key FROM central_installation_identity WHERE singleton_id = 1');
$stmt->execute();
$publicText = $stmt->fetchColumn();
$b64 = static fn(string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
$public = base64_decode(strtr((string) $publicText, '-_', '+/'), true);
$signature = base64_decode(strtr((string) $body['signature'], '-_', '+/'), true);
$message = implode("\n", [
    'HAFZATECH-INSTALL-CHECK-V1', $body['installation_id'], $body['customer_id'],
    $body['request_time'], $body['nonce'], (string) $body['known_revision'],
]);
if (!is_string($public) || !is_string($signature)
    || !sodium_crypto_sign_verify_detached($signature, $message, $public)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_installation_proof']);
    exit;
}
$privateRaw = trim((string) file_get_contents($privateFile));
$private = base64_decode(strtr($privateRaw, '-_', '+/'), true);
$receipt = [
    'api_version' => 'v1', 'issuer' => 'HAFZATECH',
    'installation_id' => $body['installation_id'], 'customer_id' => $body['customer_id'],
    'nonce' => $body['nonce'], 'status' => 'signed_revision_pending_delivery',
    'revision' => max(1, (int) $body['known_revision']),
    'issued_at' => gmdate('Y-m-d\TH:i:s\Z'),
];
$raw = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$signed = [
    'key_id' => 'browser-e2e-fixture', 'payload' => $b64($raw),
    'signature' => $b64(sodium_crypto_sign_detached(
        "HAFZATECH-INSTALL-STATUS-RECEIPT-V1\n".$raw, $private)),
];
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'api_version' => 'v1',
    'registered_installation_id' => $body['installation_id'],
    'nonce' => $body['nonce'],
    'status' => 'signed_revision_pending_delivery',
    'revision' => $receipt['revision'],
    'signed_receipt' => $signed,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
