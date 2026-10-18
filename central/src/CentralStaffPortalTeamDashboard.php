<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Minimal, actionable, role-scoped vendor Staff Administrator home.
 * POST forms remain disabled unless the independent operator HTTP release is
 * approved. No grant/disable/update form is available, only safe read-only
 * views and self-only password change/session logout.
 */
final class CentralStaffPortalTeamDashboard
{
    public function __construct(
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    public function render(string $bearer,string $csrf):string
    {
        if(!$this->authorization->permits($bearer,'staff.roles.assign',null)) {
            throw new RegistrationError('staff_team_dashboard_denied');
        }
        if(preg_match('/^[a-f0-9]{64}$/D',$csrf)!==1) {
            throw new RegistrationError('invalid_staff_dashboard');
        }
        $csrf=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $hidden='<input type="hidden" name="staff_csrf" value="'.$csrf.'">';
        return '<section aria-label="Private HAFZATECH team dashboard">'.
            '<h1>HAFZATECH staff administration</h1>'.
            '<p>Current vendor staff accounts and role assignments. '.
            'Read-only. No self-elevation or unapproved role changes.</p>'.
            '<nav aria-label="Staff administration navigation">'.
            '<form method="post" action="/staff/team/accounts">'.$hidden.
            '<button type="submit">Staff accounts</button></form>'.
            '<form method="post" action="/staff/team/roles">'.$hidden.
            '<label>Staff ID <input type="text" name="subject_staff_id" '.
            'maxlength="36" required></label>'.
            '<button type="submit">View assigned roles</button></form>'.
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
