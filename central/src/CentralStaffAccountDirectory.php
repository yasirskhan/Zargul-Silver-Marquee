<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL vendor-only staff directory for MFA-authenticated global Staff
 * Administrators/Super Administrators. No staff writes, client users,
 * password hashes, MFA seed/ciphertext, login attempts or session tokens.
 */
final class CentralStaffAccountDirectory
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listStaff(string $bearer,int $offset=0,int $limit=25): array
    {
        if($offset<0 || $offset>10000 || $limit<1 || $limit>25) {
            throw new RegistrationError('invalid_staff_directory_request',422);
        }
        if(!$this->authorization->permits($bearer,'staff.roles.assign',null)) {
            throw new RegistrationError('staff_directory_denied');
        }
        $q=$this->db->prepare(
            "SELECT u.id,u.email,u.display_name,u.status,
                    (u.mfa_enrolled_at IS NOT NULL) AS mfa_enrolled,
                    (SELECT count(*) FROM central_staff_role_grants g
                     WHERE g.staff_id=u.id AND g.revoked_at IS NULL) AS active_role_count
             FROM central_staff_users u
             ORDER BY u.created_at DESC,u.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->bindValue(':offset',$offset,PDO::PARAM_INT);
        $q->execute();
        return array_map(static function(array $row):array {
            return [
                'id'=>(string)$row['id'],
                'email'=>(string)$row['email'],
                'display_name'=>(string)$row['display_name'],
                'status'=>(string)$row['status'],
                'mfa_enrolled'=>in_array($row['mfa_enrolled'],[true,'t','1',1],true),
                'active_role_count'=>(int)$row['active_role_count'],
            ];
        },$q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Escaped, bounded read-only HTML. The renderer never accepts raw SQL. */
    public static function html(array $rows):string
    {
        $esc=static fn(mixed $v):string=>htmlspecialchars(
            is_scalar($v)?(string)$v:'',
            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $body='';
        foreach(array_slice($rows,0,25) as $row) {
            $body.='<tr><td>'.$esc($row['display_name']??'').
                '</td><td>'.$esc($row['email']??'').
                '</td><td>'.$esc($row['status']??'').
                '</td><td>'.(($row['mfa_enrolled']??false)===true?'Enrolled':'Not enrolled').
                '</td><td>'.$esc($row['active_role_count']??'0').'</td></tr>';
        }
        if($body==='') {
            $body='<tr><td colspan="5">No staff accounts</td></tr>';
        }
        return '<section aria-label="Private HAFZATECH staff directory">'.
            '<h2>Vendor staff accounts</h2><p>Read-only account information. '.
            'Changes to roles, passwords, MFA and account status are not available here.</p>'.
            '<table><thead><tr><th>Name</th><th>Email</th><th>Status</th>'.
            '<th>MFA</th><th>Active roles</th></tr></thead><tbody>'.
            $body.'</tbody></table></section>';
    }
}
