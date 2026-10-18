<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Atomic local acceptance of a delivered signed grant paired to a fresh,
 * pinned vendor status receipt. This is NOT an HTTP endpoint or owner action.
 * Only a fixed-origin authenticated HTTPS transport may supply a challenge
 * nonce generated on the local server. No production caller is wired yet.
 */
final class CentralVerifiedLicenseDeliveryService
{
    public function __construct(
        private readonly CentralSignedStatusReceiptVerifier $receiptVerifier,
        private readonly CentralSignedGrantVerifier $grantVerifier,
        private readonly CentralSignedLicenseCache $cache,
        private readonly CentralLicenseCheckSchedule $checkSchedule
    ) {}

    /**
     * An already installed, unexpired signed grant may be checked online
     * WITHOUT creating another license revision. Requires a fresh pinned
     * vendor receipt for that EXACT local revision and installation. Never
     * treats an unsigned HTTPS status response as a central license check.
     * @param array<string,mixed> $receiptEnvelope
     * @return array{revision:int,active:bool,changed:bool}
     */
    public function verifyExisting(array $receiptEnvelope, string $customerId,
        string $domain, string $nonce, DateTimeImmutable $now): array
    {
        if (config('central_control.activation_enabled') !== true) {
            throw new AuthorizationException('Central licensing is disabled.');
        }
        return DB::transaction(function () use ($receiptEnvelope,$customerId,$domain,$nonce,$now): array {
            $binding=DB::table('central_installation_activation')
                ->where('singleton_id',1)->first();
            $identity=DB::table('central_installation_identity')
                ->where('singleton_id',1)->first();
            if (! $binding || ! $identity || $binding->company_id === null ||
                $binding->installation_id !== $identity->installation_id ||
                $binding->customer_id !== $customerId ||
                $binding->approved_domain !== $domain ||
                $binding->status !== 'registered_pending_license') {
                throw new InvalidArgumentException('Immutable customer and company binding is required.');
            }
            $stored=DB::table('central_signed_license_cache')
                ->where('singleton_id',1)->lockForUpdate()->first();
            if (! $stored || $stored->installation_id !== $identity->installation_id) {
                throw new InvalidArgumentException('No enrolled local signed license to check.');
            }
            $receipt=$this->receiptVerifier->verify($receiptEnvelope,
                $identity->installation_id,$customerId,$nonce,$now);
            if ($receipt['status'] !== 'signed_revision_pending_delivery' ||
                $receipt['revision'] !== (int)$stored->revision) {
                throw new InvalidArgumentException('Vendor status is not an exact unchanged paid revision.');
            }
            $cached=$this->cache->locallyVerified($customerId,$domain,$now);
            // While an early paid renewal is signed but not yet effective,
            // the current active term is still authenticated by the pinned
            // previous signature. A same-revision online check is valid
            // when the separately signed scheduled renewal is stored.
            $effectiveRevision=(int)($cached['scheduled_renewal']['revision']
                ?? $cached['grant']['revision']);
            if ($cached['active'] !== true ||
                $effectiveRevision !== (int)$stored->revision ||
                $cached['grant']['license_id'] !== $stored->license_id) {
                throw new InvalidArgumentException('Existing signed license is invalid or expired.');
            }
            $this->checkSchedule->recordVerifiedSuccess($now);
            return ['revision'=>(int)$stored->revision,'active'=>true,'changed'=>false];
        });
    }

    /**
     * @param array<string,mixed> $grantEnvelope
     * @param array<string,mixed> $receiptEnvelope
     * @return array{license_id:string,revision:int,changed:bool,active:bool}
     */
    public function accept(array $grantEnvelope, array $receiptEnvelope,
        string $customerId, string $approvedDomain, string $requestNonce,
        DateTimeImmutable $now): array
    {
        if (config('central_control.activation_enabled') !== true) {
            throw new AuthorizationException('Central licensing remains disabled.');
        }

        return DB::transaction(function () use ($grantEnvelope, $receiptEnvelope,
            $customerId, $approvedDomain, $requestNonce, $now): array {
            $binding=DB::table('central_installation_activation')
                ->where('singleton_id',1)->first();
            $identity=DB::table('central_installation_identity')
                ->where('singleton_id',1)->first();
            if (! $binding || ! $identity ||
                $binding->installation_id !== $identity->installation_id ||
                $binding->customer_id !== $customerId ||
                $binding->approved_domain !== $approvedDomain ||
                $binding->status !== 'registered_pending_license') {
                throw new InvalidArgumentException('Enrolled customer and domain are required.');
            }
            $receipt=$this->receiptVerifier->verify($receiptEnvelope,
                $identity->installation_id,$customerId,$requestNonce,$now);
            if ($receipt['status'] !== 'signed_revision_pending_delivery') {
                throw new InvalidArgumentException('Signed receipt does not authorize a license delivery.');
            }
            $grant=$this->grantVerifier->verify($grantEnvelope,
                $customerId,$identity->installation_id,$approvedDomain,0,$now);
            if ($grant['grant']['revision'] !== $receipt['revision']) {
                throw new InvalidArgumentException('Signed grant revision differs from signed receipt.');
            }
            // Both cryptographic proofs were validated against enrolled
            // identity. Preserve monotonic license ID and revision, then
            // advance the online-success date in the SAME transaction.
            $result=$this->cache->accept($grantEnvelope,$customerId,$approvedDomain,$now);
            // Signed early renewals are future-dated. They must not erase the
            // active previous paid term or report the venue unlicensed.
            $effective=$this->cache->locallyVerified($customerId,$approvedDomain,$now);
            $this->checkSchedule->recordVerifiedSuccess($now);
            return [
                'license_id'=>$result['license_id'],
                'revision'=>$result['revision'],
                'changed'=>$result['changed'],
                'active'=>$effective['active'],
            ];
        });
    }
}
