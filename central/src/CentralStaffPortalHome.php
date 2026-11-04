<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Internal, role-driven starting point for a current vendor MFA staff session.
 * This is NOT a public endpoint, session issuer or permission elevation.
 * Every linked action independently checks live roles and scoped capabilities.
 */
final class CentralStaffPortalHome
{
    public function __construct(
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization,
        private readonly ?PDO $db=null
    ) {}

    public function render(string $bearer,string $csrf):string
    {
        if ($this->sessions->staffFor($bearer)===null) {
            throw new RegistrationError('staff_portal_denied');
        }
        if (preg_match('/^[a-f0-9]{64}$/D',$csrf)!==1) {
            throw new RegistrationError('invalid_staff_home_request',422);
        }
        $metrics='';
        if($this->db!==null && $this->authorization->permits($bearer,'audit.read',null)) {
            $count=static function(PDO $db,string $sql):int {
                return (int)$db->query($sql)->fetchColumn();
            };
            $metrics='<section aria-label="Central operations summary"><h2>Control Center overview</h2>'
                .'<div class="central-metrics">'
                .'<div class="metric-card"><strong>'.$count($this->db,'SELECT count(*) FROM vendor_customers').'</strong><span>Customers</span></div>'
                .'<div class="metric-card"><strong>'.$count($this->db,"SELECT count(*) FROM registered_installations WHERE status='active'").'</strong><span>Active installations</span></div>'
                .'<div class="metric-card"><strong>'.$count($this->db,'SELECT count(*) FROM central_licenses').'</strong><span>Licenses</span></div>'
                .'<div class="metric-card"><strong>'.$count($this->db,"SELECT count(*) FROM central_vendor_invoices WHERE status='issued'").'</strong><span>Issued invoices</span></div>'
                .'<div class="metric-card"><strong>'.$count($this->db,"SELECT count(*) FROM vendor_support_tickets WHERE status NOT IN ('resolved','closed')").'</strong><span>Open support tickets</span></div>'
                .'<div class="metric-card"><strong>'.$count($this->db,"SELECT count(*) FROM central_staff_users WHERE status='active'").'</strong><span>Active staff</span></div>'
                .'</div></section>';
        }        $proof=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $hidden='<input type="hidden" name="staff_csrf" value="'.$proof.'">';
        $action=static fn(string $uri,string $name):string=>
            '<form method="post" action="'.$uri.'">'.$hidden.
            '<button type="submit">'.$name.'</button></form>';
        $sections='';
        if (getenv('CENTRAL_VENDOR_PLAN_PUBLISH_ENABLED')==='true' &&
            $this->authorization->permits($bearer,'plan.version.create',null)) {
            $sections.='<section aria-label="Plan administration">'.
                '<h2>Subscription plans</h2>'.
                '<p>Create immutable six- or twelve-month prices and review history. '.
                'Publishing cannot issue invoices or activate licenses.</p>'.
                $action('/staff/plans','Open plan catalog').'</section>';
        }
        if ($this->authorization->permits($bearer,'staff.roles.assign',null)) {
            $sections.='<section aria-label="Team administration">'.
                '<h2>Staff and access</h2>'.
                '<p>Review staff role requests and access. Independent approvals '.
                'remain mandatory.</p>'.
                $action('/staff/team/dashboard','Open team dashboard').'</section>';
        }
        if ($this->authorization->permits($bearer,'audit.read',null)) {
            $sections.='<section aria-label="Audit records">'.
                '<h2>Security and audit</h2>'.
                '<p>Inspect permitted immutable staff history without '.
                'changing operational or financial records.</p>'.
                $action('/staff/audit/dashboard','Open audit dashboard').'</section>';
        }
        $sections .= '<section aria-label="Customer selection">'
            .'<h2>Find a customer</h2>'
            .'<p>Search only within your current Central permissions, then open an authorized workspace.</p>'
            .'<form method="post" action="/staff/customers/directory">'.$hidden
            .'<label>Business name prefix <input name="search" maxlength="64" required></label>'
            .'<button type="submit">Find authorized customers</button></form></section>';
        if ($this->authorization->permitsAnyScope($bearer,'license.read_status')) {
            $sections .= '<section aria-label="License operations"><h2>Licenses</h2>'
                .'<p>Enter a customer ID you already know is within scope. Access is authorized again by the licensing service.</p>'
                .'<form method="post" action="/staff/licenses/pending">'.$hidden
                .'<label>Customer ID <input name="customer_id" required pattern="[0-9a-fA-F-]{36}" maxlength="36"></label>'
                .'<button type="submit">Open pending licenses</button></form></section>';
        }
        if ($this->authorization->permitsAnyScope($bearer,'support.ticket.read')) {
            $sections .= '<section aria-label="Support operations"><h2>Support</h2>'
                .'<form method="post" action="/staff/support/tickets">'.$hidden
                .'<label>Customer ID <input name="customer_id" required pattern="[0-9a-fA-F-]{36}" maxlength="36"></label>'
                .'<button type="submit">Open support queue</button></form></section>';
        }
        if ($this->authorization->permitsAnyScope($bearer,'billing.invoice.read')) {
            $sections .= '<section aria-label="Billing operations"><h2>Billing</h2>'
                .'<form method="post" action="/staff/billing/overview">'.$hidden
                .'<label>Customer ID <input name="customer_id" required pattern="[0-9a-fA-F-]{36}" maxlength="36"></label>'
                .'<label>Invoice ID <input name="invoice_id" required pattern="[0-9a-fA-F-]{36}" maxlength="36"></label>'
                .'<button type="submit">Open billing overview</button></form></section>';
        }        // A staff member may hold ONLY a customer-scoped role; never infer
        // its privileges from null-scoped checks or enumerate customers here.
        // The target customer and permission are independently checked by
        // the existing /staff/dashboard protected route on each request.
        $sections.='<section aria-label="Customer workspace">'.
            '<h2>Customer workspace</h2>'.
            '<p>Use a known authorized customer ID. Access is checked again '.
            'for billing, licensing or support. No search across customers '.
            'or account information is exposed here.</p>'.
            '<form method="post" action="/staff/customers/directory">'.$hidden.
            '<input type="hidden" name="search" value="">'.
            '<button type="submit">Find assigned customers</button></form>'.
            '<form method="post" action="/staff/dashboard">'.$hidden.
            '<label>Customer ID <input name="customer_id" type="text" '.
            'maxlength="36" pattern="[0-9a-fA-F-]{36}" required></label>'.
            '<button type="submit">Open customer workspace</button></form>'.
            '</section>';
        return '<section aria-label="Private HAFZATECH staff home">'.
            '<h1>HAFZATECH Central Control Center</h1>'.$metrics.
            '<p>Private, capability-based navigation. Financial settlement and '.
            'license signing require independent review and are not '.
            'available from this page.</p>'.
            '<nav aria-label="Authorized staff navigation">'.$sections.
            $action('/staff/session/logout','Sign out').'</nav></section>';
    }
}
