<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * INTERNAL auditor home, read-only. Role and identity are derived from the
 * current independent HAFZATECH MFA session, never posted user role claims.
 * No staff management, settlement, activation or signing controls.
 */
final class CentralStaffPortalAuditorDashboard
{
    public function __construct(
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    public function render(string $bearer,string $csrf):string
    {
        if(!$this->authorization->permits($bearer,'audit.read',null)) {
            throw new RegistrationError('staff_auditor_dashboard_denied');
        }
        if(preg_match('/^[a-f0-9]{64}$/D',$csrf)!==1) {
            throw new RegistrationError('invalid_staff_auditor_dashboard');
        }
        $csrf=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $hidden='<input type="hidden" name="staff_csrf" value="'.$csrf.'">';
        return '<section aria-label="Private HAFZATECH auditor dashboard">'.
            '<h1>HAFZATECH security audit</h1>'.
            '<p>Read-only access to immutable staff session, account action '.
            'and pending staff creation metadata. No operational changes.</p>'.
            '<nav aria-label="Auditor navigation">'.
            '<form method="post" action="/staff/home">'.$hidden.
            '<button type="submit">Back to staff home</button></form>'.
            '<form method="post" action="/staff/audit/staff">'.$hidden.
            '<label>Staff ID <input type="text" name="subject_staff_id" '.
            'maxlength="36" required></label>'.
            '<button type="submit">Review staff history</button></form>'.
            '<form method="post" action="/staff/account/password/change">'.
            $hidden.'<label>Current password <input type="password" '.
            'name="current_password" autocomplete="current-password" required></label>'.
            '<label>New password <input type="password" name="new_password" '.
            'autocomplete="new-password" minlength="12" required></label>'.
            '<button type="submit">Change my password</button></form>'.
            '<form method="post" action="/staff/session/logout">'.$hidden.
            '<button type="submit">Sign out</button></form>'.
            '</nav></section>';
    }
}
