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
            $this->checkSchedule->recordVerifiedSuccess($now);
            return [
                'license_id'=>$result['license_id'],
                'revision'=>$result['revision'],
                'changed'=>$result['changed'],
                'active'=>$grant['active'],
            ];
        });
    }
}
