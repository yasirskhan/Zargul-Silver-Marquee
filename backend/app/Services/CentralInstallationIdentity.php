<?php

namespace App\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Local-only client identity. No central call, license grant, or financial
 * side effect. Only authorized installer orchestration should invoke this;
 * no public or staff endpoint is exposed in this milestone.
 */
final class CentralInstallationIdentity
{
    private const TRUSTED_ORIGIN = 'https://lichall.hafzatech.com';

    public function trustedEndpoint(string $kind): string
    {
        $path = match ($kind) {
            'challenge' => '/api/v1/installations/challenge',
            'register' => '/api/v1/installations/register',
            default => throw new InvalidArgumentException('Unknown signed installation operation.'),
        };

        return self::TRUSTED_ORIGIN.$path;
    }

    /** @return array{installation_id:string,public_key:string} */
    public function initialize(): array
    {
        $this->assertActivationEnabled();

        return DB::transaction(function (): array {
            // Constant transaction-scoped PostgreSQL lock serializes first boot
            // even when two PHP workers start activation simultaneously.
            DB::select('SELECT pg_advisory_xact_lock(708116, 1111)');
            $identity = DB::table('central_installation_identity')->where('singleton_id', 1)->first();
            if ($identity) {
                return ['installation_id' => $identity->installation_id,
                    'public_key' => $identity->ed25519_public_key];
            }
            $pair = sodium_crypto_sign_keypair();
            $public = self::base64Url(sodium_crypto_sign_publickey($pair));
            $private = self::base64Url(sodium_crypto_sign_secretkey($pair));
            $id = (string) Str::uuid();
            DB::table('central_installation_identity')->insert([
                'singleton_id' => 1,
                'installation_id' => $id,
                'ed25519_public_key' => $public,
                'encrypted_private_key' => Crypt::encryptString($private),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['installation_id' => $id, 'public_key' => $public];
        });
    }

    /**
     * Builds exactly the canonical v1 central registration contract.
     * Caller must send only to trustedEndpoint('register') using validated TLS.
     * This method cannot store an unverified central response or license.
     *
     * @return array<string,string>
     */
    public function signRegistration(
        string $enrollmentId, string $enrollmentToken, string $customerId,
        string $domain, string $nonce
    ): array {
        $this->assertActivationEnabled();
        foreach ([$enrollmentId, $customerId] as $uuid) {
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid)) {
                throw new InvalidArgumentException('Canonical v4 enrollment and customer IDs required.');
            }
        }
        if (! self::validDomain($domain)) {
            throw new InvalidArgumentException('A verified canonical DNS domain is required.');
        }
        self::decode($enrollmentToken, 32);
        self::decode($nonce, 32);

        $identity = DB::table('central_installation_identity')->where('singleton_id', 1)->first();
        if (! $identity) throw new InvalidArgumentException('Initialize the client identity first.');
        $private = self::decode(Crypt::decryptString($identity->encrypted_private_key),
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $message = implode("\n", [
            'HAFZATECH-INSTALL-REGISTER-V1', $identity->installation_id, $customerId,
            $enrollmentId, $domain, $nonce, $identity->ed25519_public_key,
        ]);
        $signature = sodium_crypto_sign_detached($message, $private);

        return [
            'enrollment_id' => $enrollmentId,
            'enrollment_token' => $enrollmentToken,
            'installation_id' => $identity->installation_id,
            'customer_id' => $customerId,
            'domain' => $domain,
            'nonce' => $nonce,
            'public_key' => $identity->ed25519_public_key,
            'signature' => self::base64Url($signature),
        ];
    }

    private function assertActivationEnabled(): void
    {
        if (config('central_control.activation_enabled') !== true) {
            throw new AuthorizationException('Central activation is disabled until approval.');
        }
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function decode(string $value, int $length): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new InvalidArgumentException('Invalid canonical base64url encoding.');
        }
        $binary = base64_decode(strtr($value, '-_', '+/'), true);
        if ($binary === false || strlen($binary) !== $length || self::base64Url($binary) !== $value) {
            throw new InvalidArgumentException('Invalid canonical base64url key length.');
        }
        return $binary;
    }

    private static function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253 && $domain === strtolower($domain)
            && filter_var($domain, FILTER_VALIDATE_IP) === false
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain) === 1;
    }
}
