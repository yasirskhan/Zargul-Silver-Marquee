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
            // A renewal paid early may have not_before at the EXISTING paid
            // expiry, weeks/months in the future. Preserve the currently
            // valid, independently signed revision during that gap.
            // Refuse a second future-start revision until the previous
            // future renewal actually begins; we store one active predecessor.
            $prior = [
                'previous_revision' => null,
                'previous_envelope_sha256' => null,
                'previous_encrypted_signed_envelope' => null,
            ];
            $starts = new DateTimeImmutable($grant['not_before']);
            if ($existing && $starts > $now) {
                $oldEnvelope = $this->verifiedStoredEnvelope(
                    $existing->encrypted_signed_envelope,$existing->envelope_sha256);
                $old = $this->verifier->verify($oldEnvelope,$customerId,
                    $identity->installation_id,$domain,
                    max(0,(int)$existing->revision - 1),$now);
                if ($old['active'] !== true ||
                    $old['grant']['revision'] !== (int)$existing->revision ||
                    $old['grant']['license_id'] !== $grant['license_id'] ||
                    $old['grant']['expires_at'] !== $grant['not_before']) {
                    throw new InvalidArgumentException(
                        'Early renewal must follow the current, active signed paid term.');
                }
                $prior = [
                    'previous_revision' => (int)$existing->revision,
                    'previous_envelope_sha256' => $existing->envelope_sha256,
                    'previous_encrypted_signed_envelope' => $existing->encrypted_signed_envelope,
                ];
            }
            $payload = $prior + [
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
        $envelope = $this->verifiedStoredEnvelope(
            $record->encrypted_signed_envelope,$record->envelope_sha256);
        $verified = $this->verifier->verify($envelope, $customerId,
            $identity->installation_id, $domain, max(0, (int)$record->revision - 1), $now);
        if ($verified['grant']['license_id'] !== $record->license_id ||
            $verified['grant']['revision'] !== (int)$record->revision) {
            throw new InvalidArgumentException('Cached revision binding mismatch.');
        }

        // Do not deactivate a valid original paid term merely because a
        // later, already-signed renewal is delivered before its effective
        // start. Verify BOTH signed revisions and their exact continuity.
        $newStarts = new DateTimeImmutable($verified['grant']['not_before']);
        if ($newStarts > $now && $record->previous_revision !== null) {
            if (! is_string($record->previous_encrypted_signed_envelope) ||
                ! is_string($record->previous_envelope_sha256)) {
                throw new InvalidArgumentException('Previous signed grant is incomplete.');
            }
            $previous = $this->verifiedStoredEnvelope(
                $record->previous_encrypted_signed_envelope,
                $record->previous_envelope_sha256);
            $prior = $this->verifier->verify($previous,$customerId,
                $identity->installation_id,$domain,
                max(0,(int)$record->previous_revision - 1),$now);
            if ($prior['active'] !== true ||
                $prior['grant']['revision'] !== (int)$record->previous_revision ||
                $prior['grant']['license_id'] !== $verified['grant']['license_id'] ||
                $prior['grant']['expires_at'] !== $verified['grant']['not_before']) {
                throw new InvalidArgumentException('Signed renewal chain is not continuous.');
            }
            return [
                'grant' => $prior['grant'],
                'active' => true,
                'scheduled_renewal' => [
                    'revision' => $verified['grant']['revision'],
                    'effective_at' => $verified['grant']['not_before'],
                    'expires_at' => $verified['grant']['expires_at'],
                ],
            ];
        }
        return $verified;
    }

    /** @return array<string,mixed> */
    private function verifiedStoredEnvelope(string $encrypted,string $hash): array
    {
        $raw=Crypt::decryptString($encrypted);
        if (! hash_equals($hash,hash('sha256',$raw))) {
            throw new InvalidArgumentException('Local signed license cache tampered.');
        }
        $decoded=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Invalid locally sealed signed envelope.');
        }
        return $decoded;
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
