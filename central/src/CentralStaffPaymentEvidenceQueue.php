<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * PRIVATE READ-ONLY vendor billing inbox for CUSTOMER-SUBMITTED payment
 * evidence. A screenshot, claimed reference or "reviewed" flag NEVER proves
 * independently settled funds and MUST NOT be inserted into the verified
 * settlement ledger on the strength of this queue.
 *
 * No public route, attachment download, bank account or payment side effect.
 * Live vendor MFA session and scoped billing rights are rechecked per read.
 */
final class CentralStaffPaymentEvidenceQueue
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
            throw new RegistrationError('invalid_payment_evidence_query',422);
        }
        if (!$this->authorization->permits(
            $vendorSessionBearer, 'billing.payment.verify', $customerId)) {
            throw new RegistrationError('billing_role_required');
        }
        $q=$this->db->prepare(
            "SELECT e.id AS evidence_id,e.invoice_id,i.invoice_number,
                    i.currency,i.total_minor,
                    left(e.claimed_reference,160) AS claimed_reference,
                    e.created_at
             FROM central_vendor_payment_evidence e
             JOIN central_vendor_invoices i ON i.id=e.invoice_id
             JOIN vendor_customers c ON c.id=i.customer_id AND c.status='active'
             WHERE i.customer_id=:customer AND i.status='issued'
               AND e.status='pending'
             ORDER BY e.created_at ASC,e.id ASC
             LIMIT :limit OFFSET :offset"
        );
        $q->bindValue(':customer',$customerId,PDO::PARAM_STR);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->bindValue(':offset',$offset,PDO::PARAM_INT);
        $q->execute();
        // All rows are unverified customer CLAIMS, not settled money. Do not
        // expose private storage object keys or bank receiving instructions.
        return array_map(static fn(array $row):array =>
            $row+['verification_state'=>'customer_claim_unverified'],
            $q->fetchAll(PDO::FETCH_ASSOC));
    }
}
