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

    public function render(string $sessionBearer,string $customerId): string
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
        return '<section aria-label="Private HAFZATECH staff dashboard">'.
            '<h1>HAFZATECH Staff</h1><p>Selected customer: '.$name.
            ' ('.$id.')</p>'.$sections.'</section>';
    }
}
