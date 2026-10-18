<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Authentication-only check-in, NOT online entitlement verification.
 * The central status reply is protected by fixed-host TLS and nonce matching,
 * but it is NOT a signed grant. Never advance last_online_verified_at, accept
 * revisions, renew, or mark a revoked/suspended license valid from this reply.
 */
final class CentralInstallationStatusTransport
{
    public function __construct(
        private readonly CentralSignedStatusReceiptVerifier $receiptVerifier,
        private readonly CentralVerifiedLicenseDeliveryService $licenseDelivery
    ) {}

    public function check(DateTimeImmutable $now): array
    {
        if (config('central_control.activation_enabled') !== true) {
            throw new AuthorizationException('Central connectivity is not released.');
        }
        $binding = DB::table('central_installation_activation')->where('singleton_id', 1)->first();
        $identity = DB::table('central_installation_identity')->where('singleton_id', 1)->first();
        if (! $binding || ! $identity || $binding->installation_id !== $identity->installation_id ||
            $binding->status !== 'registered_pending_license') {
            throw new InvalidArgumentException('Trusted central installation binding is required.');
        }
        $revision = (int) (DB::table('central_signed_license_cache')->where('singleton_id', 1)->value('revision') ?? 0);
        $nonce = self::b64(random_bytes(32));
        $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $message = implode("\n", [
            'HAFZATECH-INSTALL-CHECK-V1', $identity->installation_id,
            $binding->customer_id, $stamp, $nonce, (string) $revision,
        ]);
        $raw = Crypt::decryptString($identity->encrypted_private_key);
        $private = base64_decode(strtr($raw, '-_', '+/'), true);
        if (! is_string($private) || strlen($private) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ||
            self::b64($private) !== $raw) {
            throw new InvalidArgumentException('Local installation key has invalid format.');
        }
        $request = [
            'installation_id' => $identity->installation_id,
            'customer_id' => $binding->customer_id,
            'request_time' => $stamp,
            'nonce' => $nonce,
            'known_revision' => $revision,
            'signature' => self::b64(sodium_crypto_sign_detached($message, $private)),
        ];
        $response = Http::acceptJson()->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(3)->timeout(8)
            ->post('https://lichall.hafzatech.com/api/v1/installations/status', $request);

        if ($response->status() !== 200) {
            throw new InvalidArgumentException('Trusted central status check failed.');
        }
        $body = $response->json();
        if (! is_array($body) || ($body['api_version'] ?? null) !== 'v1' ||
            ($body['registered_installation_id'] ?? null) !== $identity->installation_id ||
            ($body['nonce'] ?? null) !== $nonce ||
            ! is_int($body['revision'] ?? null) || $body['revision'] < 0 ||
            $body['revision'] > 2147483647 ||
            ! in_array($body['status'] ?? null,
                ['registered_pending_license', 'signed_revision_pending_delivery',
                 'suspended', 'revoked'], true) ||
            ($body['status'] === 'registered_pending_license' && $body['revision'] !== 0) ||
            ($body['status'] === 'signed_revision_pending_delivery' && $body['revision'] < 1) ||
            (in_array($body['status'], ['suspended', 'revoked'], true) &&
             ! array_key_exists('signed_receipt', $body))) {
            throw new InvalidArgumentException('Central status response failed nonce or identity validation.');
        }
        // The optional signed receipt authenticates the vendor's report,
        // but NOT a paid grant. Never change last-online-license-verified,
        // central license cache or tenant release gates on this path.
        $result = [
            'status' => $body['status'],
            'revision_available' => $body['revision'],
            'licensed' => false,
        ];
        if (array_key_exists('signed_grant', $body) &&
            $body['signed_grant'] !== null &&
            (! is_array($body['signed_grant']) ||
             ! array_key_exists('signed_receipt', $body))) {
            throw new InvalidArgumentException('Delivered license is missing trusted signed receipt.');
        }
        if (array_key_exists('signed_receipt', $body)) {
            if (! is_array($body['signed_receipt'])) {
                throw new InvalidArgumentException('Malformed signed status receipt.');
            }
            $signed = $this->receiptVerifier->verify(
                $body['signed_receipt'], $identity->installation_id,
                $binding->customer_id, $nonce, $now
            );
            if ($signed['status'] !== $body['status'] ||
                $signed['revision'] !== $body['revision']) {
                throw new InvalidArgumentException('Signed receipt disagrees with central status.');
            }
            $result['vendor_signed'] = true;
            $result['receipt_issued_at'] = $signed['verified_at'];
            if (isset($body['signed_grant'])) {
                if ($signed['status'] !== 'signed_revision_pending_delivery') {
                    throw new InvalidArgumentException('License attached to non-delivery signed status.');
                }
                $accepted = $this->licenseDelivery->accept(
                    $body['signed_grant'], $body['signed_receipt'],
                    $binding->customer_id, $binding->approved_domain, $nonce, $now
                );
                $result['licensed'] = $accepted['active'];
                $result['license_revision_accepted'] = $accepted['revision'];
                $result['license_changed'] = $accepted['changed'];
            }
            if (! isset($body['signed_grant']) &&
                $signed['status'] === 'signed_revision_pending_delivery' &&
                $signed['revision'] === $revision) {
                // Vendor attests the very same existing revision. Re-verify
                // the encrypted locally signed grant before recording a
                // successful check; never issue or amend that revision.
                $checked=$this->licenseDelivery->verifyExisting(
                    $body['signed_receipt'],$binding->customer_id,
                    $binding->approved_domain,$nonce,$now
                );
                $result['licensed']=$checked['active'];
                $result['license_changed']=false;
                $result['license_revision_accepted']=$checked['revision'];
            }
            if (in_array($signed['status'], ['suspended', 'revoked'], true)) {
                // Inform future authorized UI without mutating entitlements.
                // Public/operations gates must enforce only AFTER a separately
                // implemented durable signed revocation state is verified.
                $result['requires_license_attention'] = true;
            }
        }

        return $result;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
