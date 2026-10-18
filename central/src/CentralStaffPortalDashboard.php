<?php
declare(strict_types=1);

namespace Hafzatech\Central;

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
        private readonly CentralStaffCustomerLookup $customers
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
                $forms.='<form method="post" action="/staff/billing/overview">'.
                    $base.'<label>Invoice ID <input type="text" '.
                    'name="invoice_id" maxlength="36" required></label>'.
                    '<button type="submit">Billing overview</button></form>';
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
            ' ('.$id.')</p>'.$sections.$forms.'</section>';
    }
}
