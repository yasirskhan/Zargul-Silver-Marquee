<?php
declare(strict_types=1);
namespace Hafzatech\Central;
use PDO;

/** Vendor-wide effective signed-license expiry watch for audit-capable staff. */
final class CentralStaffLicenseExpiryDirectory
{
    public function __construct(private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization) {}

    /** @return array<int,array<string,mixed>> */
    public function rows(string $bearer): array
    {
        if ($this->sessions->staffFor($bearer) === null) {
            throw new RegistrationError('staff_session_required');
        }
        if (! $this->authorization->permits($bearer, 'audit.read', null)) {
            throw new RegistrationError('staff_portal_denied');
        }
        $q = $this->db->query(
            "SELECT l.id,l.customer_id,c.legal_name,l.approved_domain,
                    r.expires_at,r.term_months
             FROM central_licenses l
             JOIN vendor_customers c ON c.id=l.customer_id
             JOIN central_paid_license_revisions r
               ON r.license_id=l.id AND r.revision=l.current_revision
             WHERE r.state='signed' AND r.expires_at IS NOT NULL
               AND r.expires_at <= now() + interval '30 days'
             ORDER BY r.expires_at ASC,l.id ASC LIMIT 100"
        );
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
