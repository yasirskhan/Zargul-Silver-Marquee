<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Read-only eligibility calculation. No signing or invoice mutation occurs.
 * Future paid issuance must lock invoice/settlement rows, verify reversals and
 * chargebacks, authorize actor sessions and audit the signed grant revision.
 */
final class PaidInvoiceEligibility
{
    public function __construct(private readonly PDO $db) {}

    public function isEligible(string $invoiceId, string $licenseOfficerId): bool
    {
        $q = $this->db->prepare(
            "SELECT i.id,i.customer_id,i.currency,i.total_minor,i.receiving_account_id
             FROM central_vendor_invoices i
             JOIN vendor_customers c ON c.id=i.customer_id AND c.status='active'
             JOIN central_vendor_plan_versions p ON p.id=i.plan_version_id AND p.currency=i.currency
             JOIN central_vendor_receiving_accounts a ON a.id=i.receiving_account_id
                 AND a.status='verified' AND a.receiving_currency=i.currency
             JOIN central_customer_provider_assignments m
                 ON m.customer_id=i.customer_id AND m.receiving_account_id=a.id
                 AND m.enabled=true AND m.effective_from<=now()
                 AND (m.effective_to IS NULL OR m.effective_to>now())
             WHERE i.id=:invoice AND i.status='issued'"
        );
        $q->execute(['invoice'=>$invoiceId]);
        $invoice=$q->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) return false;

        $issuer=$this->db->prepare(
            "SELECT 1 FROM central_staff_users u
             WHERE u.id=:officer AND u.status='active' AND u.mfa_enrolled_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM central_staff_role_grants g
                   WHERE g.staff_id=u.id AND g.role='license_officer'
                   AND g.revoked_at IS NULL
                   AND (g.customer_scope_id IS NULL OR g.customer_scope_id=:customer))
               AND NOT EXISTS (SELECT 1 FROM central_staff_role_grants forbidden
                   WHERE forbidden.staff_id=u.id AND forbidden.role='billing_officer'
                   AND forbidden.revoked_at IS NULL)"
        );
        $issuer->execute(['officer'=>$licenseOfficerId,'customer'=>$invoice['customer_id']]);
        if (!$issuer->fetchColumn()) return false;

        $q=$this->db->prepare(
            "SELECT COALESCE(SUM(s.amount_minor - COALESCE((SELECT SUM(r.amount_minor) FROM central_vendor_settlement_reversals r WHERE r.settlement_id=s.id),0)),0) FROM central_vendor_verified_settlements s
             JOIN central_staff_users verifier ON verifier.id=s.verified_by
                 AND verifier.status='active' AND verifier.mfa_enrolled_at IS NOT NULL
             WHERE s.invoice_id=:invoice AND s.receiving_account_id=:account
               AND s.currency=:currency AND s.verified_by<>:officer
               AND EXISTS (SELECT 1 FROM central_staff_role_grants g
                   WHERE g.staff_id=s.verified_by AND g.role='billing_officer'
                   AND g.revoked_at IS NULL
                   AND (g.customer_scope_id IS NULL OR g.customer_scope_id=:customer))"
        );
        $q->execute(['invoice'=>$invoiceId,'account'=>$invoice['receiving_account_id'],
            'currency'=>$invoice['currency'],'officer'=>$licenseOfficerId,
            'customer'=>$invoice['customer_id']]);
        return bccomp((string)$q->fetchColumn(),(string)$invoice['total_minor'],0)>=0;
    }
}
