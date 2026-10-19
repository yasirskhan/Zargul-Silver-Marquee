<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Read-only customer-scoped vendor invoice index. This is metadata, not proof
 * of funds: no payment evidence, bank coordinates, signing or customer PII.
 */
final class CentralStaffVendorInvoiceDirectory
{
    private const UUID='/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D';

    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listForCustomer(string $staffBearer,string $customerId,int $limit=25):array
    {
        if(preg_match(self::UUID,$customerId)!==1 ||
            $limit<1 || $limit>25) {
            throw new RegistrationError('invalid_invoice_directory_request',422);
        }
        if(!$this->authorization->permits(
            $staffBearer,'billing.invoice.read',$customerId)) {
            throw new RegistrationError('staff_invoice_directory_denied');
        }
        $q=$this->db->prepare(
            "SELECT i.id,i.invoice_number,i.status,i.currency,i.total_minor,
                    i.issued_at,p.term_months
             FROM central_vendor_invoices i
             JOIN vendor_customers c ON c.id=i.customer_id AND c.status='active'
             JOIN central_vendor_plan_versions p ON p.id=i.plan_version_id
             WHERE i.customer_id=:customer
             ORDER BY i.created_at DESC,i.id DESC LIMIT :max"
        );
        $q->bindValue(':customer',$customerId,PDO::PARAM_STR);
        $q->bindValue(':max',$limit,PDO::PARAM_INT);
        $q->execute();
        $rows=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[]=[
                'invoice_id'=>$row['id'],
                'invoice_number'=>$row['invoice_number'],
                'status'=>$row['status'],
                'currency'=>$row['currency'],
                'total_minor'=>(string)$row['total_minor'],
                'issued_at'=>$row['issued_at'],
                'term_months'=>(int)$row['term_months'],
            ];
        }
        return $rows;
    }
}
