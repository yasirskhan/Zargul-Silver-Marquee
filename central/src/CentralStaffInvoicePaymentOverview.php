<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Protected read-only vendor billing status for future staff portal.
 * Never returns receiving-account instructions, payment references,
 * settlement verifier identities, evidence, tokens or signing material.
 *
 * COVERED is a numeric net-payment indicator, NOT authorization to issue
 * a grant: paid signing must still re-check current invoice, role, receiver,
 * installation, refund/chargeback and independent settlement constraints.
 */
final class CentralStaffInvoicePaymentOverview
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array{invoice_id:string,invoice_number:string,customer_id:string,currency:string,status:string,total_minor:string,verified_minor:string,reversed_minor:string,net_minor:string,net_covered:bool}|null */
    public function forInvoice(string $vendorSessionBearer, string $invoiceId): ?array
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $invoiceId)) {
            return null;
        }
        if ($this->sessions->staffFor($vendorSessionBearer)===null) return null;

        $q=$this->db->prepare(
            "SELECT i.id,i.invoice_number,i.customer_id,i.currency,i.status,i.total_minor
             FROM central_vendor_invoices i
             JOIN vendor_customers c ON c.id=i.customer_id AND c.status='active'
             WHERE i.id=:invoice"
        );
        $q->execute(['invoice'=>$invoiceId]);
        $invoice=$q->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) return null;
        $customer=$invoice['customer_id'];
        if (!$this->authorization->permits($vendorSessionBearer,'billing.invoice.read',$customer)
            && !$this->authorization->permits($vendorSessionBearer,'license.read_status',$customer)
            && !$this->authorization->permits($vendorSessionBearer,'audit.read',$customer)) {
            return null;
        }

        // Same independent Billing Officer eligibility conditions as the
        // paid-license preparer. Avoid listing individual payment references.
        $q=$this->db->prepare(
            "SELECT COALESCE(SUM(s.amount_minor),0) AS paid,
                COALESCE(SUM(COALESCE((
                    SELECT SUM(r.amount_minor) FROM central_vendor_settlement_reversals r
                    WHERE r.settlement_id=s.id),0)),0) AS reversed
             FROM central_vendor_verified_settlements s
             JOIN central_staff_users v ON v.id=s.verified_by
                 AND v.status='active' AND v.mfa_enrolled_at IS NOT NULL
             WHERE s.invoice_id=:invoice AND s.currency=:currency
               AND EXISTS (SELECT 1 FROM central_staff_role_grants g
                   WHERE g.staff_id=v.id AND g.role='billing_officer'
                     AND g.revoked_at IS NULL
                     AND (g.customer_scope_id IS NULL OR g.customer_scope_id=:customer))"
        );
        // The dashboard covers independently verified money regardless of
        // which authorized viewer is reading it.
        $q->execute(['invoice'=>$invoiceId,'currency'=>$invoice['currency'],
            'customer'=>$customer]);
        $money=$q->fetch(PDO::FETCH_ASSOC);
        $gross=(string)($money['paid']??'0');
        $reversed=(string)($money['reversed']??'0');
        $net=bcsub($gross,$reversed,0);
        return [
            'invoice_id'=>$invoice['id'],
            'invoice_number'=>$invoice['invoice_number'],
            'customer_id'=>$customer,
            'currency'=>$invoice['currency'],
            'status'=>$invoice['status'],
            'total_minor'=>(string)$invoice['total_minor'],
            'verified_minor'=>$gross,
            'reversed_minor'=>$reversed,
            'net_minor'=>$net,
            'net_covered'=>$invoice['status']==='issued'
                && bccomp($net,(string)$invoice['total_minor'],0)>=0
        ];
    }
}
