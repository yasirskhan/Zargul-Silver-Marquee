<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Read-only safe metadata for a customer invoice provider selector.
 * The caller MUST first authenticate and authorize the central CUSTOMER;
 * never expose this method directly as an anonymous endpoint.
 * Actual encrypted receiving instructions require separate secure delivery
 * and customer/invoice-specific authorization, not this projection.
 */
final class VendorReceivingDirectory
{
    public function __construct(private readonly PDO $db) {}

    /** @return array<int,array{id:string,provider:string,label:string,currency:string,country:string}> */
    public function allowedForCustomer(string $customerId, string $invoiceCurrency): array
    {
        if (!preg_match('/^[A-Z]{3}$/D', $invoiceCurrency) ||
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $customerId)) {
            return [];
        }
        $statement = $this->db->prepare(
            "SELECT a.id, a.provider_kind AS provider, a.display_label AS label,
                    a.receiving_currency AS currency, a.country_code AS country
             FROM central_customer_provider_assignments mapping
             JOIN central_vendor_receiving_accounts a
                 ON a.id = mapping.receiving_account_id
             JOIN vendor_customers customer
                 ON customer.id = mapping.customer_id
             WHERE mapping.customer_id = :customer
               AND customer.status = 'active'
               AND mapping.enabled = true
               AND mapping.effective_from <= now()
               AND (mapping.effective_to IS NULL OR mapping.effective_to > now())
               AND a.status = 'verified' AND a.receiving_currency = :currency
               AND EXISTS (
                   SELECT 1 FROM central_staff_users verifier
                   JOIN central_staff_role_grants grant_role ON grant_role.staff_id = verifier.id
                       AND grant_role.role = 'billing_officer' AND grant_role.revoked_at IS NULL
                       AND (grant_role.customer_scope_id IS NULL
                            OR grant_role.customer_scope_id = mapping.customer_id)
                   WHERE verifier.id = a.verified_by AND verifier.status = 'active'
                       AND verifier.mfa_enrolled_at IS NOT NULL
               )
             ORDER BY a.provider_kind, a.display_label, a.id"
        );
        $statement->execute(['customer'=>$customerId, 'currency'=>$invoiceCurrency]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
