<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL, exact-ID, customer-scoped vendor lookup for a protected staff
 * dashboard. No unbounded search, tenant password/user data, enrollment
 * secrets, bank instructions, installation credentials, invoices or keys.
 * A live vendor MFA session and CURRENT read capability are required.
 */
final class CentralStaffCustomerLookup
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array{id:string,legal_name:string,status:string}|null */
    public function find(string $staffBearer,string $customerId): ?array
    {
        if (preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $customerId)!==1) {
            throw new RegistrationError('invalid_customer_lookup',422);
        }
        if (!$this->authorization->permits(
            $staffBearer,'license.read_status',$customerId) &&
            !$this->authorization->permits(
                $staffBearer,'billing.invoice.read',$customerId) &&
            !$this->authorization->permits(
                $staffBearer,'support.ticket.read',$customerId)) {
            throw new RegistrationError('staff_customer_scope_denied');
        }
        $q=$this->db->prepare(
            "SELECT id,legal_name,status FROM vendor_customers
             WHERE id=:customer AND status='active'"
        );
        $q->execute(['customer'=>$customerId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id'=>(string)$row['id'],
            'legal_name'=>(string)$row['legal_name'],
            'status'=>(string)$row['status']
        ];
    }

    /** Escaped, narrow read-only private vendor screen. */
    public static function html(array $customer): string
    {
        $e=static fn(mixed $x):string=>htmlspecialchars(
            is_scalar($x)?(string)$x:'',
            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return '<section aria-label="Private HAFZATECH customer lookup">'.
            '<h2>Customer</h2><dl><dt>Customer ID</dt><dd>'.
            $e($customer['id']??'').
            '</dd><dt>Registered business</dt><dd>'.
            $e($customer['legal_name']??'').
            '</dd><dt>Account status</dt><dd>'.
            $e($customer['status']??'').
            '</dd></dl><p>Read-only. A visible customer account '.
            'does not confirm payment or authorize license issuance.</p>'.
            '</section>';
    }
}
