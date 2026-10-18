<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL read-only vendor role assignment projection. Only a current
 * MFA-authenticated global staff administrator may inspect a named employee's
 * active grants. Reading does NOT grant a capability, approve an exception,
 * change a role, or disclose staff MFA/password secrets.
 */
final class CentralStaffRoleGrantDirectory
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listForStaff(string $bearer,string $targetStaffId):array
    {
        if(preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $targetStaffId)!==1) {
            throw new RegistrationError('invalid_staff_roles_request',422);
        }
        if(!$this->authorization->permits($bearer,'staff.roles.assign',null)) {
            throw new RegistrationError('staff_roles_denied');
        }
        $q=$this->db->prepare(
            "SELECT role,customer_scope_id,created_at
             FROM central_staff_role_grants
             WHERE staff_id=:target AND revoked_at IS NULL
             ORDER BY role,created_at,id LIMIT 25"
        );
        $q->execute(['target'=>$targetStaffId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Escaped role and scope metadata, with no privilege edit controls. */
    public static function html(array $roles):string
    {
        $e=static fn(mixed $x):string=>htmlspecialchars(
            is_scalar($x)?(string)$x:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $table='';
        foreach(array_slice($roles,0,25) as $row) {
            $scope=$row['customer_scope_id']??null;
            $table.='<tr><td>'.$e($row['role']??'').
                '</td><td>'.($scope===null?'Global':$e($scope)).
                '</td><td>'.$e($row['created_at']??'').'</td></tr>';
        }
        if($table==='')$table='<tr><td colspan="3">No active role grants</td></tr>';
        return '<section aria-label="Private HAFZATECH staff roles">'.
            '<h2>Assigned staff roles</h2><p>Read-only. Role changes require '.
            'separate audited authorization and independent approvals.</p>'.
            '<table><thead><tr><th>Role</th><th>Customer scope</th>'.
            '<th>Assigned at</th></tr></thead><tbody>'.$table.
            '</tbody></table></section>';
    }
}
