<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL worker-only finalization boundary. The authorized License Officer
 * comes from an active password+TOTP vendor session, never from an HTTP staff
 * ID or caller-provided "MFA passed" Boolean. This does not authenticate KMS
 * workers, expose an endpoint, create a signature, or enable production signer.
 * A separately authenticated isolated worker supplies a signed envelope.
 */
final class CentralStaffSignedGrantFinalization
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization,
        private readonly SignedGrantFinalization $finalization
    ) {}

    /** @param array<string,mixed> $signedEnvelope
     * @return array{revision_id:string,license_id:string,revision:int,state:string}
     */
    public function finalize(string $vendorSessionBearer, string $revisionId,
        bool $authenticatedSignerWorker, array $signedEnvelope): array
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $revisionId) !== 1) {
            throw new RegistrationError('invalid_license_request', 422);
        }
        $actor = $this->sessions->staffFor($vendorSessionBearer);
        if ($actor === null) {
            throw new RegistrationError('staff_session_required');
        }
        $query = $this->db->prepare(
            'SELECT r.authorized_officer_id, l.customer_id
             FROM central_paid_license_revisions r
             JOIN central_licenses l ON l.id=r.license_id
             WHERE r.id=:revision'
        );
        $query->execute(['revision'=>$revisionId]);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            throw new RegistrationError('signed_grant_not_eligible');
        }
        // Never allow a different vendor officer, a Billing Officer, or a
        // license-scoped officer from another customer to finalize this grant.
        if ($record['authorized_officer_id'] !== $actor ||
            !$this->authorization->permits(
                $vendorSessionBearer, 'license.issue_paid', $record['customer_id']
            )) {
            throw new RegistrationError('license_officer_required');
        }

        // Existing transaction-locked validator independently rechecks paid
        // net settlement, current grants, revision and signed claim contents.
        // The worker-trust parameter MUST be supplied by a protected worker,
        // never accepted from browser JSON or customer installation input.
        return $this->finalization->finalize(
            $revisionId, $actor, true, $authenticatedSignerWorker, $signedEnvelope
        );
    }
}
