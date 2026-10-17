<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Authenticates central entitlements entirely offline, without a network call.
 * Trusted Ed25519 public keys ship in the HAFZATECH-controlled application
 * build. No central signing secret, license URL override or tenant data upload.
 * No live access gate is wired until approved expiry/grace policies ship.
 */
final class CentralSignedGrantVerifier
{
    /**
     * @return array{grant:array<string,mixed>,active:bool}
     */
    public function verify(
        array $envelope, string $customerId, string $installationId,
        string $approvedDomain, int $lowestAcceptedRevision,
        DateTimeImmutable $now
    ): array {
        $fields = array_keys($envelope);
        sort($fields);
        if ($fields !== ['key_id', 'payload', 'signature'] ||
            ! is_string($envelope['key_id']) ||
            ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $envelope['key_id'])) {
            throw new InvalidArgumentException('Invalid signed license envelope.');
        }
        $keys = config('central_control.trusted_signing_public_keys', []);
        if (! is_array($keys) || ! isset($keys[$envelope['key_id']]) ||
            ! is_string($keys[$envelope['key_id']])) {
            throw new InvalidArgumentException('Untrusted central signing key.');
        }
        $key = self::decode($keys[$envelope['key_id']], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $signature = self::decode($envelope['signature'], SODIUM_CRYPTO_SIGN_BYTES);
        $payloadBytes = self::decode($envelope['payload'], null);
        if (strlen($payloadBytes) > 16384 || ! sodium_crypto_sign_verify_detached(
            $signature, 'HAFZATECH-LICENSE-GRANT-V1'."\n".$payloadBytes, $key
        )) {
            throw new InvalidArgumentException('Invalid central signature.');
        }
        $grant = json_decode($payloadBytes, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($grant) || array_is_list($grant) ||
            ($grant['issuer'] ?? null) !== 'HAFZATECH' ||
            ($grant['product'] ?? null) !== 'VenueDesk' ||
            ($grant['api_version'] ?? null) !== 'v1' ||
            ! self::uuid($grant['license_id'] ?? null) ||
            ! self::uuid($grant['customer_id'] ?? null) ||
            ! self::uuid($grant['installation_id'] ?? null) ||
            $grant['customer_id'] !== $customerId ||
            $grant['installation_id'] !== $installationId ||
            ! is_int($grant['revision'] ?? null) ||
            $grant['revision'] <= $lowestAcceptedRevision ||
            ! in_array($grant['term_months'] ?? null, [6,12], true) ||
            ! is_string($grant['edition'] ?? null) ||
            ! preg_match('/^[a-z0-9_-]{1,64}$/D', $grant['edition'])) {
            throw new InvalidArgumentException('License issuer, binding or revision is invalid.');
        }
        $domains = $grant['approved_domains'] ?? null;
        if (! is_array($domains) || ! array_is_list($domains) ||
            count($domains) < 1 || count($domains) > 20 ||
            ! in_array($approvedDomain, $domains, true)) {
            throw new InvalidArgumentException('Approved venue domain does not match.');
        }
        foreach ($domains as $domain) {
            if (! is_string($domain) || strlen($domain) > 253 ||
                ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain)) {
                throw new InvalidArgumentException('Invalid signed domain.');
            }
        }
        $limits = $grant['limits'] ?? null;
        if (! is_array($limits) ||
            ! is_int($limits['max_halls'] ?? null) || $limits['max_halls'] < 1 ||
            ! is_int($limits['max_staff'] ?? null) || $limits['max_staff'] < 1 ||
            $limits['max_halls'] > 10000 || $limits['max_staff'] > 100000) {
            throw new InvalidArgumentException('Invalid signed feature limits.');
        }
        $features = $grant['features'] ?? null;
        if (! is_array($features) || array_is_list($features) || count($features) > 150) {
            throw new InvalidArgumentException('Invalid signed feature map.');
        }
        foreach ($features as $name => $enabled) {
            if (! is_string($name) ||
                ! preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $name) ||
                ! is_bool($enabled)) {
                throw new InvalidArgumentException('Invalid signed module entitlement.');
            }
        }
        $starts = self::utcInstant($grant['not_before'] ?? null);
        $ends = self::utcInstant($grant['expires_at'] ?? null);
        if ($starts >= $ends) throw new InvalidArgumentException('Invalid signed license term.');
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
        // The expired signature remains authentic. The caller must display
        // maintenance and enforce the separate signed 7-day backend grace.
        return ['grant'=>$grant, 'active'=>$nowUtc >= $starts && $nowUtc < $ends];
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) &&
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private static function utcInstant(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $value)) {
            throw new InvalidArgumentException('Invalid ISO-UTC signed license instant.');
        }
        $utc = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (! $utc || $utc->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('Invalid UTC date in signed license.');
        }
        return $utc;
    }

    private static function decode(mixed $value, ?int $length): string
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new InvalidArgumentException('Invalid canonical signature encoding.');
        }
        $binary = base64_decode(strtr($value, '-_', '+/'), true);
        if ($binary === false || ($length !== null && strlen($binary) !== $length) ||
            rtrim(strtr(base64_encode($binary), '+/', '-_'), '=') !== $value) {
            throw new InvalidArgumentException('Invalid signed byte length or encoding.');
        }
        return $binary;
    }
}
