<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL operator's pending-signature queue. No central HTTP route.
 * A current password+TOTP HAFZATECH staff bearer, NOT a submitted actor ID,
 * must have live customer-scoped license status or paid-issuance permission.
 * Financial records, signing keys, enrollment secrets and tokens are omitted.
 * Queue presence NEVER authorizes signing: the worker must recheck payment,
 * role, signed claims and release gates transactionally at finalization.
 */
final class CentralStaffLicenseSigningQueue
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listPending(string $vendorSessionBearer, string $customerId,
        int $offset=0, int $limit=25): array
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $customerId) !== 1 ||
            $offset<0 || $offset>10000 || $limit<1 || $limit>100) {
            throw new RegistrationError('invalid_license_request', 422);
        }
        if (!$this->authorization->permits($vendorSessionBearer,'license.read_status',$customerId) &&
            !$this->authorization->permits($vendorSessionBearer,'license.issue_paid',$customerId)) {
            throw new RegistrationError('license_officer_required');
        }
        $q=$this->db->prepare(
            "SELECT r.id AS revision_id,r.license_id,r.revision,r.state,
                    r.created_at,r.expires_at,
                    i.invoice_number,l.approved_domain
             FROM central_paid_license_revisions r
             JOIN central_licenses l ON l.id=r.license_id
             JOIN central_vendor_invoices i
                ON i.id=r.invoice_id AND i.customer_id=l.customer_id
             JOIN vendor_customers c ON c.id=l.customer_id AND c.status='active'
             JOIN registered_installations inst ON inst.id=l.installation_id
                AND inst.customer_id=l.customer_id AND inst.status='active'
             WHERE l.customer_id=:customer
                AND r.state='awaiting_isolated_signer'
                AND r.revision=l.current_revision
             ORDER BY r.created_at DESC,r.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $q->bindValue(':customer',$customerId,PDO::PARAM_STR);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->bindValue(':offset',$offset,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
