<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use RuntimeException;
use SodiumException;
use Throwable;

final class RegistrationError extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 403)
    {
        parent::__construct($errorCode);
    }
}

/**
 * Customer and domain verification occur in the trusted HAFZATECH staff/customer
 * workflow BEFORE a one-use enrollment token is provisioned. This API never
 * grants entitlement or issues a signed license.
 */
final class InstallationRegistry
{
    public function __construct(private readonly PDO $db) {}

    /** @return array{api_version:string,nonce:string,expires_in_seconds:int} */
    public function challenge(string $enrollmentId, string $token): array
    {
        self::requireUuid($enrollmentId);
        self::requireToken($token);
        $this->db->beginTransaction();
        try {
            $enrollment = $this->enrollment($enrollmentId, $token);
            $nonce = self::base64Url(random_bytes(32));
            // One outstanding challenge per enrollment; replaces stale challenges.
            $upsert = $this->db->prepare(
                "INSERT INTO installation_challenges (enrollment_id, nonce_sha256, expires_at, consumed_at, created_at)
                 VALUES (:id, :hash, now() + interval '5 minutes', NULL, now())
                 ON CONFLICT (enrollment_id) DO UPDATE SET
                   nonce_sha256 = EXCLUDED.nonce_sha256,
                   expires_at = EXCLUDED.expires_at,
                   consumed_at = NULL,
                   created_at = now()"
            );
            $upsert->execute(['id' => $enrollment['id'], 'hash' => hash('sha256', $nonce)]);
            $this->db->commit();
            return ['api_version' => 'v1', 'nonce' => $nonce, 'expires_in_seconds' => 300];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $input
     * @return array{api_version:string,installation_id:string,customer_id:string,status:string}
     */
    public function register(array $input): array
    {
        foreach (['enrollment_id','enrollment_token','installation_id','customer_id',
            'domain','nonce','public_key','signature'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key])) {
                throw new RegistrationError('invalid_request', 422);
            }
        }
        $enrollmentId = $input['enrollment_id'];
        $installationId = $input['installation_id'];
        $customerId = $input['customer_id'];
        self::requireUuid($enrollmentId);
        self::requireUuid($installationId);
        self::requireUuid($customerId);
        self::requireToken($input['enrollment_token']);
        $publicKey = self::decodeKey($input['public_key'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $signature = self::decodeKey($input['signature'], SODIUM_CRYPTO_SIGN_BYTES);
        $nonce = self::decodeKey($input['nonce'], 32);
        $domain = self::canonicalDomain($input['domain']);
        $message = self::signingMessage($installationId, $customerId, $enrollmentId,
            $domain, $input['nonce'], $input['public_key']);
        if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new RegistrationError('invalid_proof');
        }

        $this->db->beginTransaction();
        try {
            $enrollment = $this->enrollment($enrollmentId, $input['enrollment_token']);
            if ($enrollment['customer_id'] !== $customerId || $enrollment['approved_domain'] !== $domain) {
                throw new RegistrationError('identity_mismatch');
            }
            $challenge = $this->db->prepare(
                'SELECT nonce_sha256 FROM installation_challenges
                 WHERE enrollment_id = :id AND consumed_at IS NULL AND expires_at > now()
                 FOR UPDATE'
            );
            $challenge->execute(['id' => $enrollmentId]);
            $row = $challenge->fetch(PDO::FETCH_ASSOC);
            if (!$row || !hash_equals(trim($row['nonce_sha256']), hash('sha256', $input['nonce']))) {
                throw new RegistrationError('challenge_expired_or_replayed');
            }
            $stmt = $this->db->prepare(
                "INSERT INTO registered_installations
                   (id, customer_id, enrollment_id, approved_domain, ed25519_public_key)
                 VALUES (:id, :customer, :enrollment, :domain, :public_key)"
            );
            $stmt->execute(['id' => $installationId, 'customer' => $customerId,
                'enrollment' => $enrollmentId, 'domain' => $domain,
                'public_key' => $input['public_key']]);
            $stmt = $this->db->prepare(
                "UPDATE installation_enrollments SET claimed_at = now()
                 WHERE id = :id AND claimed_at IS NULL"
            );
            $stmt->execute(['id' => $enrollmentId]);
            if ($stmt->rowCount() !== 1) throw new RegistrationError('enrollment_already_claimed', 409);
            $stmt = $this->db->prepare(
                "UPDATE installation_challenges SET consumed_at = now() WHERE enrollment_id = :id"
            );
            $stmt->execute(['id' => $enrollmentId]);
            $stmt = $this->db->prepare(
                "INSERT INTO central_identity_audit
                   (customer_id, installation_id, enrollment_id, event, details)
                 VALUES (:customer, :installation, :enrollment, 'installation.registered', :details)"
            );
            $stmt->execute(['customer' => $customerId, 'installation' => $installationId,
                'enrollment' => $enrollmentId,
                'details' => json_encode(['domain' => $domain, 'key_algorithm' => 'Ed25519'], JSON_THROW_ON_ERROR)]);
            $this->db->commit();
            return ['api_version' => 'v1', 'installation_id' => $installationId,
                'customer_id' => $customerId, 'status' => 'registered_pending_license'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public static function signingMessage(string $installationId, string $customerId,
        string $enrollmentId, string $domain, string $nonce, string $publicKey): string
    {
        return implode("\n", ['HAFZATECH-INSTALL-REGISTER-V1', $installationId, $customerId,
            $enrollmentId, $domain, $nonce, $publicKey]);
    }

    public static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function decodeKey(string $value, int $length): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new RegistrationError('invalid_cryptographic_encoding', 422);
        }
        $binary = base64_decode(strtr($value, '-_', '+/'), true);
        if ($binary === false || strlen($binary) !== $length ||
            self::base64Url($binary) !== $value) {
            throw new RegistrationError('invalid_cryptographic_encoding', 422);
        }
        return $binary;
    }

    private static function canonicalDomain(string $value): string
    {
        if ($value !== strtolower($value) || strlen($value) > 253 ||
            filter_var($value, FILTER_VALIDATE_IP) ||
            !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value)) {
            throw new RegistrationError('invalid_domain', 422);
        }
        return $value;
    }

    private static function requireUuid(string $value): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new RegistrationError('invalid_identifier', 422);
        }
    }

    private static function requireToken(string $token): void
    {
        // Provisioned tokens must be 256-bit random base64url, never a password.
        self::decodeKey($token, 32);
    }

    /** @return array<string,mixed> */
    private function enrollment(string $id, string $token): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.id, e.customer_id, e.approved_domain, e.token_sha256
             FROM installation_enrollments e
             JOIN vendor_customers c ON c.id = e.customer_id
             WHERE e.id = :id AND e.expires_at > now()
               AND e.claimed_at IS NULL AND e.domain_verified_at IS NOT NULL
               AND c.status = 'active' FOR UPDATE OF e"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals(trim($row['token_sha256']), hash('sha256', $token))) {
            throw new RegistrationError('invalid_enrollment');
        }
        return $row;
    }
}
