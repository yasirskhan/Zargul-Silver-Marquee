<?php

namespace App\Services;

use App\Models\User;

/**
 * Owner-authorized INTERNAL installation enrollment orchestration.
 * Does not expose an installer route, activate a signed paid license, or
 * store vendor enrollment tokens. The trusted transport checks central
 * challenge + signed installation identity; the local owner/company binding
 * is independently re-authorized after the HTTPS round-trip.
 */
final class CentralOwnerInstallationOnboarding
{
    public function __construct(
        private readonly CentralInstallationCompanyScope $scope,
        private readonly CentralInstallationRegistrationTransport $registration
    ) {}

    /** @return array{installation_id:string,customer_id:string,status:string,company_id:string} */
    public function enroll(User $actor, string $companyId,
        string $enrollmentId, string $oneUseToken,
        string $vendorCustomerId, string $verifiedDomain): array
    {
        // Reject manager/foreign/unverified users BEFORE any network call or
        // local signing identity is created. Never accept a central URL from
        // a user. Vendor-issued proof must still pass central registration.
        $this->scope->assertCanEnroll($actor,$companyId);
        $registered=$this->registration->register(
            $enrollmentId,$oneUseToken,$vendorCustomerId,$verifiedDomain);
        $bound=$this->scope->bind($actor,$companyId);
        if ($registered['installation_id'] !== $bound['installation_id']) {
            throw new \RuntimeException('Central installation identity mismatch.');
        }
        return [
            'installation_id'=>$registered['installation_id'],
            'customer_id'=>$registered['customer_id'],
            'status'=>$registered['status'],
            'company_id'=>$bound['company_id']
        ];
    }
}
