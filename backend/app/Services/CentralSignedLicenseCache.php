<?php

namespace App\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use DateTimeImmutable;

/**
 * Locally cached signed central entitlement. No grant can be accepted unless
 * authenticated by vendor-pinned Ed25519 keys against local installation ID.
 * This is NOT a public endpoint or proof of an online central check.
 */
final class CentralSignedLicenseCache
{
    public function __construct(private readonly CentralSignedGrantVerifier $verifier) {}

    /**
     * @param array<string,mixed> $envelope
     * @return array{license_id:string,revision:int,changed:bool}
     */
    public function accept(array $envelope, string $customerId, string $domain, DateTimeImmutable $now): array
    {
        if (config('central_control.activation_enabled') !== true) {
            throw new AuthorizationException('Central activation is disabled.');
        }

        return DB::transaction(function () use ($envelope, $customerId, $domain, $now): array {
            DB::select('SELECT pg_advisory_xact_lock(708116, 1112)');
            $identity = DB::table('central_installation_identity')
                ->where('singleton_id', 1)->first();
            if (! $identity) {
                throw new InvalidArgumentException('Installation identity has not been initialized.');
            }
            $this->assertEnrollmentBinding($identity->installation_id, $customerId, $domain);
            $existing = DB::table('central_signed_license_cache')
                ->where('singleton_id', 1)->lockForUpdate()->first();

            // A repeated byte-for-byte verified response is idempotent; same
            // revision with changed contents and any rollback are forbidden.
            $canonical = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $canonical);
            $minimum = $existing ? max(0, (int) $existing->revision - 1) : 0;
            $verified = $this->verifier->verify($envelope, $customerId,
                $identity->installation_id, $domain, $minimum, $now);
            $grant = $verified['grant'];

            if ($existing && ($existing->license_id !== $grant['license_id'] ||
                (int) $existing->revision > $grant['revision'])) {
                throw new InvalidArgumentException('License ID changed or signed revision rolled back.');
            }
            if ($existing && (int) $existing->revision === $grant['revision']) {
                if (! hash_equals($existing->envelope_sha256, $hash)) {
                    throw new InvalidArgumentException('Conflicting signed revision.');
                }

                return ['license_id' => $grant['license_id'],
                    'revision' => $grant['revision'], 'changed' => false];
            }
            $payload = [
                'installation_id' => $identity->installation_id,
                'license_id' => $grant['license_id'],
                'revision' => $grant['revision'],
                'envelope_sha256' => $hash,
                'encrypted_signed_envelope' => Crypt::encryptString($canonical),
                'accepted_at' => now(),
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('central_signed_license_cache')->where('singleton_id', 1)->update($payload);
            } else {
                DB::table('central_signed_license_cache')->insert(
                    ['singleton_id' => 1, 'created_at' => now()] + $payload
                );
            }

            return ['license_id' => $grant['license_id'],
                'revision' => $grant['revision'], 'changed' => true];
        });
    }

    /** Re-authenticate cached bytes on every license-relevant check. */
    public function locallyVerified(string $customerId, string $domain, DateTimeImmutable $now): array
    {
        $identity = DB::table('central_installation_identity')
            ->where('singleton_id', 1)->first();
        if (! $identity) {
            throw new InvalidArgumentException('Installation identity is missing.');
        }
        $this->assertEnrollmentBinding($identity->installation_id, $customerId, $domain);
        $record = DB::table('central_signed_license_cache')
            ->where('singleton_id', 1)->first();
        if (! $identity || ! $record || $identity->installation_id !== $record->installation_id) {
            throw new InvalidArgumentException('No matching installed signed license.');
        }
        $raw = Crypt::decryptString($record->encrypted_signed_envelope);
        if (! hash_equals($record->envelope_sha256, hash('sha256', $raw))) {
            throw new InvalidArgumentException('Local license cache tampered.');
        }
        $envelope = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $verified = $this->verifier->verify($envelope, $customerId,
            $identity->installation_id, $domain, max(0, (int)$record->revision - 1), $now);
        if ($verified['grant']['license_id'] !== $record->license_id ||
            $verified['grant']['revision'] !== (int)$record->revision) {
            throw new InvalidArgumentException('Cached revision binding mismatch.');
        }

        return $verified;
    }

    private function assertEnrollmentBinding(string $installationId, string $customerId, string $domain): void
    {
        // Never trust caller-provided customer/domain even if a grant is
        // correctly signed for another central installation/customer.
        $binding = DB::table('central_installation_activation')
            ->where('singleton_id', 1)->first();
        if (! $binding ||
            $binding->installation_id !== $installationId ||
            $binding->customer_id !== $customerId ||
            $binding->approved_domain !== $domain ||
            $binding->status !== 'registered_pending_license') {
            throw new InvalidArgumentException('Signed grant does not match the enrolled business and domain.');
        }
    }
}
