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
            'No self-elevation or unapproved role changes.</p>'.
            '<nav aria-label="Staff administration navigation">'.
            (getenv('CENTRAL_STAFF_PROVISIONING_ENABLED')==='true' &&
              $this->authorization->permits($bearer,'staff.create',null)
                ?'<form method="post" action="/staff/team/create-pending">'.$hidden.
                 '<label>Staff email <input type="email" name="email" required></label>'.
                 '<label>Display name <input type="text" name="display_name" required></label>'.
                 '<label>Reason <input type="text" name="reason" minlength="5" required></label>'.
                 '<button type="submit">Prepare pending staff identity</button></form>'
                :''). 
            (getenv('CENTRAL_STAFF_ACTIVATION_REVIEW_ENABLED')==='true' &&
              $this->authorization->permits($bearer,'staff.create',null)
                ?'<form method="post" action="/staff/team/activation-review">'.$hidden.
                 '<label>Pending staff ID <input type="text" '.
                 'name="target_staff_id" maxlength="36" required></label>'.
                 '<button type="submit">Review activation safeguards</button></form>'
                :'').
            '<form method="post" action="/staff/team/accounts">'.$hidden.
            '<button type="submit">Staff accounts</button></form>'.
            (getenv('CENTRAL_STAFF_ROLE_REQUEST_ENABLED')==='true'
                ?self::roleRequestForm($hidden):'').
            '<form method="post" action="/staff/team/role-requests">'.$hidden.
            '<button type="submit">Pending independent role reviews</button></form>'.
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

    private static function roleRequestForm(string $hidden):string
    {
        $options='';
        foreach(array_keys(StaffRolePermissions::ROLE_PERMISSIONS) as $name) {
            $safe=htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $options.='<option value="'.$safe.'">'.$safe.'</option>';
        }
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);
        $hex=bin2hex($b);
        $id=substr($hex,0,8).'-'.substr($hex,8,4).'-'.
            substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
        return '<form method="post" action="/staff/team/role-request">'.$hidden.
            '<input type="hidden" name="request_id" value="'.$id.'">'.
            '<label>Target staff ID <input type="text" name="target_staff_id" '.
            'maxlength="36" required></label>'.
            '<label>Requested role <select name="role" required>'.
            '<option value="" disabled selected>Select a role</option>'.
            $options.'</select></label>'.
            '<label>Customer scope ID (leave blank for global) '.
            '<input type="text" name="customer_scope_id" maxlength="36"></label>'.
            '<label>Reason <input type="text" name="reason" '.
            'minlength="5" maxlength="500" required></label>'.
            '<button type="submit">Submit for independent approval</button>'.
            '</form>';
    }
}
