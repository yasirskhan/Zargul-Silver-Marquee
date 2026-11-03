<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL private role-specific vendor dashboard, never a public session
 * source. Capabilities are always derived from current central MFA sessions,
 * NOT posted roles. A customer legal name is read through the separate
 * scoped lookup. No live billing payment, license-signing or approval actions.
 */
final class CentralStaffPortalDashboard
{
    public function __construct(
        private readonly CentralStaffSessionAuthorization $authorization,
        private readonly CentralStaffCustomerLookup $customers,
        private readonly ?CentralStaffVendorInvoiceIssuer $invoiceIssuer=null,
        private readonly ?PDO $db=null
    ) {}

    public function render(string $sessionBearer,string $customerId,?string $csrf=null): string
    {
        $billing=$this->authorization->permits(
            $sessionBearer,'billing.invoice.read',$customerId);
        $licensing=$this->authorization->permits(
            $sessionBearer,'license.read_status',$customerId);
        $support=$this->authorization->permits(
            $sessionBearer,'support.ticket.read',$customerId);
        if (!$billing && !$licensing && !$support) {
            throw new RegistrationError('staff_dashboard_denied');
        }
        $customer=$this->customers->find($sessionBearer,$customerId);
        if ($customer===null) {
            throw new RegistrationError('customer_not_found');
        }
        $name=htmlspecialchars($customer['legal_name'],
            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $id=htmlspecialchars($customer['id'],
            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $overview='';
        if($this->db!==null) {
            $installations=$this->db->prepare(
                'SELECT id,approved_domain,status FROM registered_installations
                 WHERE customer_id=:customer ORDER BY created_at');
            $installations->execute(['customer'=>$customerId]);
            $installationRows=$installations->fetchAll(PDO::FETCH_ASSOC);
            $overview.='<section aria-label="Registered installations"><h2>Installations</h2>';
            if($installationRows===[]) {
                $overview.='<p>No registered installation for this customer.</p>';
            } else {
                $overview.='<table><thead><tr><th>Installation</th><th>Domain</th><th>Status</th></tr></thead><tbody>';
                foreach($installationRows as $row) {
                    $overview.='<tr><td><code>'.htmlspecialchars((string)$row['id'],ENT_QUOTES).'</code></td><td>'
                        .htmlspecialchars((string)$row['approved_domain'],ENT_QUOTES).'</td><td>'
                        .htmlspecialchars((string)$row['status'],ENT_QUOTES).'</td></tr>';
                }
                $overview.='</tbody></table>';
            }
            $overview.='</section>';
            if($licensing) {
                $licenses=$this->db->prepare(
                    'SELECT l.id,l.approved_domain,l.current_revision,r.expires_at,r.state
                     FROM central_licenses l
                     LEFT JOIN central_paid_license_revisions r
                       ON r.license_id=l.id AND r.revision=l.current_revision
                     WHERE l.customer_id=:customer ORDER BY l.created_at DESC');
                $licenses->execute(['customer'=>$customerId]);
                $overview.='<section aria-label="Licensed subscriptions"><h2>Licenses</h2>';
                $licenseRows=$licenses->fetchAll(PDO::FETCH_ASSOC);
                if($licenseRows===[]) {
                    $overview.='<p>No prepared license for this customer.</p>';
                } else {
                    $overview.='<table><thead><tr><th>License</th><th>Domain</th><th>Revision</th><th>Expires</th><th>State</th></tr></thead><tbody>';
                    foreach($licenseRows as $row) {
                        $overview.='<tr><td><code>'.htmlspecialchars((string)$row['id'],ENT_QUOTES).'</code></td><td>'
                            .htmlspecialchars((string)$row['approved_domain'],ENT_QUOTES).'</td><td>'
                            .htmlspecialchars((string)$row['current_revision'],ENT_QUOTES).'</td><td>'
                            .htmlspecialchars((string)($row['expires_at']??'—'),ENT_QUOTES).'</td><td>'
                            .htmlspecialchars((string)($row['state']??'—'),ENT_QUOTES).'</td></tr>';
                    }
                    $overview.='</tbody></table>';
                }
                $overview.='</section>';
            }
            if($billing) {
                $invoices=$this->db->prepare(
                    'SELECT invoice_number,currency,total_minor,status,issued_at
                     FROM central_vendor_invoices WHERE customer_id=:customer
                     ORDER BY created_at DESC LIMIT 10');
                $invoices->execute(['customer'=>$customerId]);
                $overview.='<section aria-label="Subscription invoices"><h2>Recent invoices</h2>';
                $invoiceRows=$invoices->fetchAll(PDO::FETCH_ASSOC);
                if($invoiceRows===[]) {
                    $overview.='<p>No subscription invoice for this customer.</p>';
                } else {
                    $overview.='<table><thead><tr><th>Invoice</th><th>Amount</th><th>Status</th><th>Issued</th></tr></thead><tbody>';
                    foreach($invoiceRows as $row) {
                        $overview.='<tr><td>'.htmlspecialchars((string)$row['invoice_number'],ENT_QUOTES).'</td><td>'
                            .htmlspecialchars((string)$row['currency'].' '.$row['total_minor'],ENT_QUOTES).'</td><td>'
                            .htmlspecialchars((string)$row['status'],ENT_QUOTES).'</td><td>'
                            .htmlspecialchars((string)($row['issued_at']??'—'),ENT_QUOTES).'</td></tr>';
                    }
                    $overview.='</tbody></table>';
                }
                $overview.='</section>';
            }
            if($support) {
                $tickets=$this->db->prepare(
                    "SELECT count(*) FROM vendor_support_tickets
                     WHERE customer_id=:customer AND status NOT IN ('resolved','closed')");
                $tickets->execute(['customer'=>$customerId]);
                $overview.='<section aria-label="Open support summary"><h2>Support</h2><p>'
                    .(int)$tickets->fetchColumn().' open support ticket(s).</p></section>';
            }
        }
        $sections='';
        if ($billing) {
            $sections.='<section aria-label="Billing permissions">'.
                '<h2>Billing Officer</h2>'.
                '<p>Read independent verified settlement, reversals and '.
                'customer-submitted UNVERIFIED payment evidence. No license '.
                'signing permission is granted here.</p></section>';
        }
        if ($licensing) {
            $sections.='<section aria-label="Licensing permissions">'.
                '<h2>License Officer</h2>'.
                '<p>Read prepared license revisions awaiting isolated '.
                'signing. Queue visibility does not approve settlement, '.
                'sign grants or activate an installation.</p></section>';
        }
        if ($support) {
            $sections.='<section aria-label="Support permissions">'.
                '<h2>Support Agent</h2>'.
                '<p>Read authorized support ticket metadata. Encrypted '.
                'messages require separately protected key access. '.
                'This screen cannot send replies or close cases.</p></section>';
        }
        $forms='';
        if(is_string($csrf) && preg_match('/^[0-9a-f]{64}$/D',$csrf)===1) {
            $safeCsrf=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8');
            $base='<input type="hidden" name="staff_csrf" value="'.$safeCsrf.'">'.
                '<input type="hidden" name="customer_id" value="'.$id.'">';
            $form=static fn(string $path,string $label):string=>
                '<form method="post" action="'.$path.'">'.$base.
                '<button type="submit">'.$label.'</button></form>';
            $forms.=$form('/staff/dashboard','Refresh dashboard');
            $forms.=$form('/staff/customers/lookup','Customer overview');
            if($licensing) {
                $forms.=$form('/staff/licenses/pending','Pending license revisions');
            }
            if($support) {
                $forms.=$form('/staff/support/tickets','Support tickets');
            }
            if($billing) {
                $forms.=$form('/staff/billing/invoices/recent','Recent subscription invoices');
                $forms.='<form method="post" action="/staff/billing/overview">'.
                    $base.'<label>Invoice ID <input type="text" '.
                    'name="invoice_id" maxlength="36" required></label>'.
                    '<button type="submit">Billing overview</button></form>';
            }
            if($billing && $this->invoiceIssuer!==null &&
                getenv('CENTRAL_VENDOR_INVOICE_ISSUANCE_ENABLED')==='true' &&
                $this->authorization->permits(
                    $sessionBearer,'billing.invoice.issue',$customerId)) {
                $offers=$this->invoiceIssuer->offers($sessionBearer,$customerId);
                $forms.='<section aria-label="Vendor invoice offers">'.
                    '<h3>Issue a HAFZATECH subscription invoice</h3>'.
                    '<p>Invoices are UNPAID until independent settlement verification. '.
                    'Issuing an invoice never renews a license.</p>';
                foreach($offers as $offer) {
                    $plan=htmlspecialchars((string)$offer['plan_version_id'],
                        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                    $accountId=htmlspecialchars((string)$offer['receiving_account_id'],
                        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                    $label=htmlspecialchars((string)$offer['plan_code'],
                        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                    $receiver=htmlspecialchars((string)$offer['display_label'],
                        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                    $price=htmlspecialchars((string)$offer['price_minor'],
                        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                    $currency=htmlspecialchars((string)$offer['currency'],
                        ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                    $requestId=self::uuid();
                    $forms.='<form method="post" action="/staff/billing/invoices/issue">'.
                        $base.
                        '<input type="hidden" name="plan_version_id" value="'.$plan.'">'.
                        '<input type="hidden" name="receiving_account_id" value="'.$accountId.'">'.
                        '<input type="hidden" name="request_id" value="'.$requestId.'">'.
                        '<button type="submit">Issue '.$label.' '.
                        (int)$offer['term_months'].' months, '.$currency.' '.
                        $price.' minor units via '.$receiver.'</button></form>';
                }
                if($offers===[]) {
                    $forms.='<p>No eligible active plan and verified receiving account.</p>';
                }
                $forms.='</section>';
            }
            $forms.='<form method="post" action="/staff/account/password/change">'.
                '<input type="hidden" name="staff_csrf" value="'.$safeCsrf.'">'.
                '<label>Current password <input type="password" '.
                'name="current_password" autocomplete="current-password" required></label>'.
                '<label>New password <input type="password" name="new_password" '.
                'autocomplete="new-password" minlength="12" required></label>'.
                '<button type="submit">Change my password</button></form>';
            $forms.='<form method="post" action="/staff/session/logout">'.
                '<input type="hidden" name="staff_csrf" value="'.$safeCsrf.'">'.
                '<button type="submit">Sign out</button></form>';
            $forms='<nav aria-label="Authorized staff actions">'.$forms.'</nav>';
        }
        return '<section aria-label="Private HAFZATECH staff dashboard">'.
            '<h1>HAFZATECH Staff</h1><p>Selected customer: '.$name.
            ' ('.$id.')</p>'.$overview.$sections.$forms.'</section>';
    }

    private static function uuid():string
    {
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.
            substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
