<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Internal, role-driven starting point for a current vendor MFA staff session.
 * This is NOT a public endpoint, session issuer or permission elevation.
 * Every linked action independently checks live roles and scoped capabilities.
 */
final class CentralStaffPortalHome
{
    public function __construct(
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    public function render(string $bearer,string $csrf):string
    {
        if ($this->sessions->staffFor($bearer)===null) {
            throw new RegistrationError('staff_portal_denied');
        }
        if (preg_match('/^[a-f0-9]{64}$/D',$csrf)!==1) {
            throw new RegistrationError('invalid_staff_home_request',422);
        }
        $proof=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
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
        // A staff member may hold ONLY a customer-scoped role; never infer
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
            '<h1>HAFZATECH Central Control Center</h1>'.
            '<p>Private, capability-based navigation. Financial settlement and '.
            'license signing require independent review and are not '.
            'available from this page.</p>'.
            '<nav aria-label="Authorized staff navigation">'.$sections.
            $action('/staff/session/logout','Sign out').'</nav></section>';
    }
}
