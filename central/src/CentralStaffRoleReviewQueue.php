<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Read-only, MFA-authorized independent-review inbox for central vendor staff.
 * Lists metadata from immutable role requests with NO decision yet. Never
 * grants, approves, rejects, or reads password/MFA/approval secret materials.
 */
final class CentralStaffRoleReviewQueue
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listPending(string $bearer,int $limit=25):array
    {
        if($limit<1||$limit>25) {
            throw new RegistrationError('invalid_staff_review_queue',422);
        }
        $actor=$this->sessions->staffFor($bearer);
        if($actor===null ||
            !$this->authorization->permits(
                $bearer,'staff.roles.assign',null)) {
            throw new RegistrationError('staff_review_queue_denied');
        }
        $q=$this->db->prepare(
            'SELECT r.id AS request_id,r.target_staff_id,r.role,
                    r.customer_scope_id,r.created_at
             FROM central_staff_role_requests r
             LEFT JOIN central_staff_role_decisions d
                ON d.request_id=r.id
             WHERE d.id IS NULL
               AND r.actor_staff_id<>:reviewer
               AND r.target_staff_id<>:reviewer
             ORDER BY r.created_at DESC,r.id DESC LIMIT :limit'
        );
        $q->bindValue(':reviewer',$actor,PDO::PARAM_STR);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function html(array $rows):string
    {
        $e=static fn(mixed $v):string=>htmlspecialchars(
            is_scalar($v)?(string)$v:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $body='';
        foreach(array_slice($rows,0,25) as $r) {
            $body.='<tr><td>'.$e($r['request_id']??'').
                '</td><td>'.$e($r['target_staff_id']??'').
                '</td><td>'.$e($r['role']??'').
                '</td><td>'.(($r['customer_scope_id']??null)===null
                    ?'Global':$e($r['customer_scope_id'])).
                '</td><td>'.$e($r['created_at']??'').'</td></tr>';
        }
        if($body==='') {
            $body='<tr><td colspan="5">No pending independent reviews</td></tr>';
        }
        return '<section aria-label="Private HAFZATECH role review queue">'.
            '<h2>Staff role requests awaiting another administrator</h2>'.
            '<p>Read-only. No role becomes active until independent MFA '.
            'review and an audited second decision are completed.</p>'.
            '<table><thead><tr><th>Request ID</th><th>Staff ID</th>'.
            '<th>Requested role</th><th>Scope</th><th>Created</th>'.
            '</tr></thead><tbody>'.$body.'</tbody></table></section>';
    }
}
