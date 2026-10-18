<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * INTERNAL read-only auditor projection. Existing immutable PostgreSQL
 * readers perform live MFA-backed audit.read authorization independently on
 * BOTH staff-session and account-action reads. No decrypted MFA, session
 * bearer, session ID, account reason or customer identity is rendered.
 */
final class CentralStaffPortalAuditHistoryPage
{
    public function __construct(
        private readonly CentralStaffSessionAuditReader $sessions,
        private readonly CentralStaffAccountActionReader $actions,
        private readonly ?CentralStaffProvisionAuditReader $provisioning=null
    ) {}

    public function render(string $vendorBearer,string $subjectStaffId): string
    {
        $sessionEvents=$this->sessions->listForStaff(
            $vendorBearer,$subjectStaffId,0,25);
        $accountEvents=$this->actions->listForTarget(
            $vendorBearer,$subjectStaffId,0,25);
        $e=static fn(mixed $x):string=>htmlspecialchars(
            is_scalar($x)?(string)$x:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $sessionRows='';
        foreach($sessionEvents as $event) {
            $sessionRows.='<tr><td>'.$e($event['outcome']??'').
                '</td><td>'.$e($event['created_at']??'').'</td></tr>';
        }
        if($sessionRows==='') {
            $sessionRows='<tr><td colspan="2">No session events</td></tr>';
        }
        $accountRows='';
        foreach($accountEvents as $event) {
            $accountRows.='<tr><td>'.$e($event['action']??'').
                '</td><td>'.$e($event['previous_status']??'').
                '</td><td>'.$e($event['occurred_at']??'').'</td></tr>';
        }
        if($accountRows==='') {
            $accountRows='<tr><td colspan="3">No account actions</td></tr>';
        }
        $provisionRows='';
        if($this->provisioning!==null) {
            foreach($this->provisioning->listForTarget(
                $vendorBearer,$subjectStaffId,25) as $event) {
                $provisionRows.='<tr><td>'.$e($event['action']??'').
                    '</td><td>'.$e($event['actor_staff_id']??'').
                    '</td><td>'.$e($event['occurred_at']??'').'</td></tr>';
            }
            if($provisionRows==='') {
                $provisionRows='<tr><td colspan="3">No provisioning events</td></tr>';
            }
            $provisionRows='<h3>Identity provisioning</h3>'.
                '<table><thead><tr><th>Action</th><th>Authorized actor ID</th>'.
                '<th>When</th></tr></thead><tbody>'.$provisionRows.'</tbody></table>';
        }
        return '<section aria-label="Private HAFZATECH staff audit">'.
            '<h2>Staff security history</h2><p>Read-only. '.
            'Session tokens, session IDs, MFA secrets and staff-account '.
            'decision reasons are not displayed.</p>'.
            '<h3>Session events</h3><table><thead><tr>'.
            '<th>Outcome</th><th>When</th></tr></thead><tbody>'.
            $sessionRows.'</tbody></table>'.
            '<h3>Account actions</h3><table><thead><tr>'.
            '<th>Action</th><th>Previous status</th><th>When</th>'.
            '</tr></thead><tbody>'.$accountRows.'</tbody></table>'.$provisionRows.'</section>';
    }
}
