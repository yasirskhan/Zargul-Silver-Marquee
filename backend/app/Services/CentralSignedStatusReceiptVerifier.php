<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pure verifier for future HAFZATECH signed online status receipts. Never
 * accepts unsigned status HTTP responses as authority and never writes DB.
 * Signer private keys remain exclusively in isolated HAFZATECH custody.
 */
final class CentralSignedStatusReceiptVerifier
{
    /**
     * @param array<string,mixed> $envelope
     * @return array{status:string,revision:int,verified_at:string}
     */
    public function verify(array $envelope, string $installationId,
        string $customerId, string $nonce, DateTimeImmutable $now): array
    {
        foreach (['key_id', 'payload', 'signature'] as $key) {
            if (! is_string($envelope[$key] ?? null)) {
                throw new InvalidArgumentException('Missing signed status receipt field.');
            }
        }
        if (count($envelope) !== 3 ||
            ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $envelope['key_id'])) {
            throw new InvalidArgumentException('Unexpected signed receipt envelope.');
        }
        $keys=config('central_control.trusted_signing_public_keys', []);
        if (! is_array($keys) || ! isset($keys[$envelope['key_id']]) ||
            ! is_string($keys[$envelope['key_id']])) {
            throw new InvalidArgumentException('Status signer not trusted.');
        }
        $pub=self::decode($keys[$envelope['key_id']], 32);
        $signature=self::decode($envelope['signature'], 64);
        $payload=self::decode($envelope['payload'], null);
        if (strlen($payload) > 4096 || ! sodium_crypto_sign_verify_detached(
            $signature, 'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".$payload, $pub
        )) {
            throw new InvalidArgumentException('Status receipt signature invalid.');
        }
        $data=json_decode($payload, true, 12, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data) ||
            ($data['api_version'] ?? null) !== 'v1' ||
            ($data['issuer'] ?? null) !== 'HAFZATECH' ||
            ($data['installation_id'] ?? null) !== $installationId ||
            ($data['customer_id'] ?? null) !== $customerId ||
            ($data['nonce'] ?? null) !== $nonce ||
            ! is_int($data['revision'] ?? null) ||
            $data['revision'] < 0 || $data['revision'] > 2147483647 ||
            ! in_array($data['status'] ?? null,
                ['registered_pending_license', 'signed_revision_pending_delivery',
                 'suspended', 'revoked'], true)) {
            throw new InvalidArgumentException('Status receipt identity or contract mismatch.');
        }
        if (($data['status'] === 'registered_pending_license') !==
            ($data['revision'] === 0)) {
            throw new InvalidArgumentException('Inconsistent signed status revision.');
        }
        $issued=$data['issued_at'] ?? null;
        if (! is_string($issued) ||
            ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$issued)) {
            throw new InvalidArgumentException('Invalid vendor receipt timestamp.');
        }
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',
            $issued, new DateTimeZone('UTC'));
        if (! $date || $date->format('Y-m-d\TH:i:s\Z') !== $issued ||
            abs($now->getTimestamp()-$date->getTimestamp()) > 300) {
            throw new InvalidArgumentException('Vendor receipt expired or ahead of clock.');
        }
        return [
            'status'=>$data['status'],
            'revision'=>$data['revision'],
            'verified_at'=>$issued,
        ];
    }

    private static function decode(string $text, ?int $size): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new InvalidArgumentException('Noncanonical receipt encoding.');
        }
        $bytes=base64_decode(strtr($text,'-_','+/'), true);
        if ($bytes === false || ($size !== null && strlen($bytes) !== $size) ||
            rtrim(strtr(base64_encode($bytes),'+/','-_'),'=') !== $text) {
            throw new InvalidArgumentException('Invalid receipt byte length.');
        }
        return $bytes;
    }
}
