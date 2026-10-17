<?php
declare(strict_types=1);

use Hafzatech\Central\InstallationRegistry;
use Hafzatech\Central\RegistrationError;

require dirname(__DIR__).'/src/InstallationRegistry.php';

function uuid(): string {
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $x = bin2hex($b);
    return substr($x, 0, 8).'-'.substr($x, 8, 4).'-'.substr($x, 12, 4).'-'.substr($x, 16, 4).'-'.substr($x, 20);
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
}
function denied(callable $fn, string $expected): void {
    try { $fn(); } catch (RegistrationError $e) {
        check($e->errorCode === $expected, 'expected '.$expected.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('FAIL: expected denial '.$expected);
}
$dsn = getenv('CENTRAL_DB_DSN');
if (!$dsn || !str_contains($dsn, 'hafzatech_central_test')) {
    throw new RuntimeException('Refuse to run tests outside the dedicated hafzatech_central_test database.');
}
$db = new PDO($dsn, getenv('CENTRAL_DB_USERNAME'), getenv('CENTRAL_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$registry = new InstallationRegistry($db);
$customer = uuid(); $foreign = uuid(); $enrollment = uuid(); $installation = uuid();
$token = InstallationRegistry::base64Url(random_bytes(32));
$db->prepare("INSERT INTO vendor_customers (id, legal_name) VALUES (:id, :name)")
    ->execute(['id'=>$customer, 'name'=>'Synthetic customer A']);
$db->prepare("INSERT INTO vendor_customers (id, legal_name) VALUES (:id, :name)")
    ->execute(['id'=>$foreign, 'name'=>'Synthetic customer B']);
$stmt = $db->prepare("INSERT INTO installation_enrollments
    (id, customer_id, token_sha256, approved_domain, domain_verified_at, expires_at)
    VALUES (:id, :customer, :hash, 'venue.example.test', now(), now() + interval '1 hour')");
$stmt->execute(['id'=>$enrollment, 'customer'=>$customer, 'hash'=>hash('sha256', $token)]);
denied(fn()=> $registry->challenge($enrollment, InstallationRegistry::base64Url(random_bytes(32))),
    'invalid_enrollment');
$challenge = $registry->challenge($enrollment, $token);
check(strlen($challenge['nonce']) === 43, 'nonce is full 256 bits');
$keys = sodium_crypto_sign_keypair();
$public = InstallationRegistry::base64Url(sodium_crypto_sign_publickey($keys));
$make = function (string $customerId, string $domain, string $nonce) use ($registry, $installation,
    $enrollment, $token, $public, $keys): array {
    $message = InstallationRegistry::signingMessage($installation, $customerId, $enrollment,
        $domain, $nonce, $public);
    return ['enrollment_id'=>$enrollment, 'enrollment_token'=>$token, 'installation_id'=>$installation,
        'customer_id'=>$customerId, 'domain'=>$domain, 'nonce'=>$nonce, 'public_key'=>$public,
        'signature'=>InstallationRegistry::base64Url(
            sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($keys)))];
};
$payload = $make($customer, 'venue.example.test', $challenge['nonce']);
$bad = $payload; $bad['signature'] = InstallationRegistry::base64Url(random_bytes(64));
denied(fn()=> $registry->register($bad), 'invalid_proof');
denied(fn()=> $registry->register($make($foreign, 'venue.example.test', $challenge['nonce'])),
    'identity_mismatch');
denied(fn()=> $registry->register($make($customer, 'other.example.test', $challenge['nonce'])),
    'identity_mismatch');
$stale = $payload; $stale['nonce'] = InstallationRegistry::base64Url(random_bytes(32));
$stale = $make($customer, 'venue.example.test', $stale['nonce']);
denied(fn()=> $registry->register($stale), 'challenge_expired_or_replayed');
check((int) $db->query('SELECT count(*) FROM registered_installations')->fetchColumn() === 0,
    'invalid requests create zero installations');
$result = $registry->register($payload);
check($result['status'] === 'registered_pending_license' &&
    $result['customer_id'] === $customer, 'registered pending payment/license');
denied(fn()=> $registry->register($payload), 'invalid_enrollment');
denied(fn()=> $registry->challenge($enrollment, $token), 'invalid_enrollment');
check((int) $db->query('SELECT count(*) FROM registered_installations')->fetchColumn() === 1,
    'replay cannot add a second identity');
check((int) $db->query('SELECT count(*) FROM central_identity_audit')->fetchColumn() === 1,
    'registration audit immutable and does not duplicate');
$unverified = uuid();
$stmt = $db->prepare("INSERT INTO installation_enrollments
    (id, customer_id, token_sha256, approved_domain, expires_at)
    VALUES (:id, :customer, :hash, 'other.example.test', now() + interval '1 hour')");
$anotherToken = InstallationRegistry::base64Url(random_bytes(32));
$stmt->execute(['id'=>$unverified, 'customer'=>$foreign, 'hash'=>hash('sha256',$anotherToken)]);
denied(fn()=> $registry->challenge($unverified, $anotherToken), 'invalid_enrollment');
$db->prepare("UPDATE installation_enrollments SET domain_verified_at = now(),
    expires_at = now() - interval '1 second' WHERE id = :id")->execute(['id'=>$unverified]);
denied(fn()=> $registry->challenge($unverified, $anotherToken), 'invalid_enrollment');
echo "PASS: central identity enrollment, signed proof, tenant binding, replay, audit, unverified/expired enrollment\n";
