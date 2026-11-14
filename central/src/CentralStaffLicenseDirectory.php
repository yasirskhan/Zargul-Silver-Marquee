<?php
declare(strict_types=1);
namespace Hafzatech\Central;

use PDO;

/** Bounded read-only customer license and revision directory. */
final class CentralStaffLicenseDirectory
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    public function canPrepare(string $bearer,string $customerId): bool
    {
        return $this->authorization->permits($bearer,'license.issue_paid',$customerId);
    }
    /** @return array<int,array<string,mixed>> */
    public function invoicesForRenewal(string $bearer,string $customerId): array
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $customerId)!==1 ||
            !$this->authorization->permits($bearer,'license.issue_paid',$customerId)) {
            throw new RegistrationError('license_officer_required');
        }
        $q=$this->db->prepare(
            "SELECT i.id,i.invoice_number,i.status,(SELECT CASE WHEN EXISTS (SELECT 1 FROM central_vendor_verified_settlements s WHERE s.invoice_id=i.id AND s.currency=i.currency AND s.amount_minor=i.total_minor) THEN 1 ELSE 0 END) AS settlement_eligible FROM central_vendor_invoices i
             WHERE i.customer_id=:customer ORDER BY i.created_at DESC,i.id LIMIT 25"
        );
        $q->execute(['customer'=>$customerId]);

        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    /** @return array<int,array<string,mixed>> */
    public function listForCustomer(string $bearer,string $customerId,int $limit=50): array
    {
        if(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $customerId)!==1 || $limit<1 || $limit>50) {
            throw new RegistrationError('invalid_license_directory_request',422);
        }
        if($this->sessions->staffFor($bearer)===null) {
            throw new RegistrationError('staff_session_required');
        }
        if(!$this->authorization->permits($bearer,'license.read_status',$customerId)) {
            throw new RegistrationError('license_directory_denied');
        }
        $q=$this->db->prepare(
            "SELECT l.id,l.installation_id,l.approved_domain,l.current_revision,l.created_at,
                    i.status AS installation_status,
                    (SELECT r.expires_at FROM central_paid_license_revisions r
                     WHERE r.license_id=l.id AND r.state='signed'
                     ORDER BY r.revision DESC LIMIT 1) AS expires_at,
                    (SELECT r.term_months FROM central_paid_license_revisions r
                     WHERE r.license_id=l.id AND r.state='signed'
                     ORDER BY r.revision DESC LIMIT 1) AS term_months,
                    (SELECT count(*) FROM central_paid_license_revisions r
                     WHERE r.license_id=l.id) AS revision_count,
                    (SELECT count(*) FROM central_paid_license_revisions r
                     WHERE r.license_id=l.id AND r.state='awaiting_isolated_signer') AS pending_count,
                    (SELECT r.id FROM central_paid_license_revisions r
                     WHERE r.license_id=l.id ORDER BY r.revision DESC LIMIT 1) AS latest_revision_id,
                    (SELECT r.state FROM central_paid_license_revisions r
                     WHERE r.license_id=l.id ORDER BY r.revision DESC LIMIT 1) AS latest_revision_state,
                    (SELECT r.invoice_id FROM central_paid_license_revisions r
                     WHERE r.license_id=l.id ORDER BY r.revision DESC LIMIT 1) AS latest_invoice_id,
                    (SELECT count(*) FROM central_license_issuance_audit a
                     WHERE a.license_id=l.id) AS issuance_audit_count
             FROM central_licenses l
             JOIN registered_installations i ON i.id=l.installation_id
                 AND i.customer_id=l.customer_id
             WHERE l.customer_id=:customer
             ORDER BY l.created_at DESC,l.id LIMIT :max"
        );
        $q->bindValue(':customer',$customerId,PDO::PARAM_STR);
        $q->bindValue(':max',$limit,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}