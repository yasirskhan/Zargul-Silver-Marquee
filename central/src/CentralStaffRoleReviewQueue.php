<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Read-only, MFA-authorized independent-review inbox for central vendor staff.
 * Lists immutable role requests with NO decision yet, including the bounded
 * stated justification for an independent human review. Never grants,
 * approves, rejects, or reads password/MFA/approval secret materials.
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
        // Review is permitted by a global staff administrative grant;
        // actions additionally require authority over this specific role.
        $rolesQuery=$this->db->prepare(
            'SELECT role FROM central_staff_role_grants
             WHERE staff_id=:reviewer AND customer_scope_id IS NULL
               AND revoked_at IS NULL ORDER BY id'
        );
        $rolesQuery->execute(['reviewer'=>$actor]);
        $globalRoles=$rolesQuery->fetchAll(PDO::FETCH_COLUMN);
        $q=$this->db->prepare(
            'SELECT r.id AS request_id,r.actor_staff_id,r.target_staff_id,r.role,
                    r.customer_scope_id,r.reason,r.created_at,
                    requester.email AS requester_email,
                    requester.status AS requester_status,
                    target.email AS target_email,target.status AS target_status,
                    scoped_customer.status AS scoped_customer_status,
                    COALESCE((SELECT jsonb_agg(g.role)
                      FROM central_staff_role_grants g
                      WHERE g.staff_id=r.actor_staff_id
                        AND g.customer_scope_id IS NULL
                        AND g.revoked_at IS NULL),jsonb_build_array())
                      AS issuer_global_roles
             FROM central_staff_role_requests r
             JOIN central_staff_users requester ON requester.id=r.actor_staff_id
             JOIN central_staff_users target ON target.id=r.target_staff_id
             LEFT JOIN vendor_customers scoped_customer
                ON scoped_customer.id=r.customer_scope_id
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
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) {
            $row['can_decide']=false;
            $row['can_reject']=false;
            $issuerRoles=json_decode((string)$row['issuer_global_roles'],true);
            unset($row['issuer_global_roles']);
            foreach($globalRoles as $role) {
                if(StaffRolePermissions::mayGrantRole($role,$row['role'],false,true)) {
                    $row['can_reject']=true;break;
                }
            }
            if(!$row['can_reject'])continue;
            // A stale or disabled participant must never receive an
            // actionable review form. The decision service rechecks the
            // same identities under lock independently of this UI guard.
            if($row['requester_status']!=='active' ||
                !in_array($row['target_status'],['pending_mfa','active'],true) ||
                ($row['customer_scope_id']!==null &&
                    $row['scoped_customer_status']!=='active')) {
                continue;
            }
            // A live, globally empowered issuer is required by the locked
            // decision transaction. Never offer an action for revoked issuers.
            $issuerCapable=false;
            if(is_array($issuerRoles)) {
                foreach($issuerRoles as $role) {
                    if(is_string($role) && StaffRolePermissions::mayGrantRole(
                        $role,$row['role'],false,true)) {
                        $issuerCapable=true;break;
                    }
                }
            }
            if(!$issuerCapable)continue;
            $row['can_decide']=true;
        }
        unset($row);
        return $rows;
    }

    public static function html(array $rows,?string $csrf=null):string
    {
        $e=static fn(mixed $v):string=>htmlspecialchars(
            is_scalar($v)?(string)$v:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $allowDecision=$csrf!==null &&
            preg_match('/^[a-f0-9]{64}$/D',$csrf)===1 &&
            getenv('CENTRAL_STAFF_ROLE_DECISIONS_ENABLED')==='true';
        $proof=$allowDecision?$e($csrf):'';
        $body='';
        foreach(array_slice($rows,0,25) as $r) {
            $body.='<tr><td>'.$e($r['request_id']??'').
                '</td><td>'.$e($r['actor_staff_id']??'').
                '<small> '.$e($r['requester_email']??'').'</small>'.
                '</td><td>'.$e($r['target_staff_id']??'').
                '<small> '.$e($r['target_email']??'').
                ' ('.$e($r['target_status']??'').')</small>'.
                '</td><td>'.$e($r['role']??'').
                '</td><td>'.(($r['customer_scope_id']??null)===null
                    ?'Global':$e($r['customer_scope_id'])).
                '</td><td>'.$e($r['reason']??'').
                '</td><td>'.$e($r['created_at']??'').'</td>'.
                ($allowDecision && ($r['can_decide']??false)===true
                    ?'<td><form method="post" action="/staff/team/role-decisions">'.
                     '<input type="hidden" name="staff_csrf" value="'.$proof.'">'.
                     '<input type="hidden" name="request_id" value="'.
                         $e($r['request_id']??'').'">'.
                     '<input type="hidden" name="decision_id" value="'.
                         self::uuidV4().'">'.
                     '<label>Outcome <select name="outcome">'.
                       '<option value="rejected">Reject</option>'.
                       '<option value="approved">Approve</option>'.
                     '</select></label>'.
                     '<label>Decision reason <input type="text" '.
                     'name="reason" minlength="5" maxlength="500" required>'.
                     '</label><button type="submit">Record decision</button>'.
                     '</form></td>'
                    :($allowDecision && ($r['can_reject']??false)===true
                      ?'<td><form method="post" action="/staff/team/role-decisions">'.
                       '<input type="hidden" name="staff_csrf" value="'.$proof.'">'.
                       '<input type="hidden" name="request_id" value="'.
                         $e($r['request_id']??'').'">'.
                       '<input type="hidden" name="decision_id" value="'.
                         self::uuidV4().'">'.
                       '<input type="hidden" name="outcome" value="rejected">'.
                       '<strong>Reject only: approval no longer permitted</strong>'.
                       '<label>Decision reason <input type="text" '.
                       'name="reason" minlength="5" maxlength="500" required>'.
                       '</label><button type="submit">Reject stale request</button>'.
                       '</form></td>'
                      :'<td>Review-only</td>')).'</tr>';
        }
        if($body==='') {
            $body='<tr><td colspan="8">No pending independent reviews</td></tr>';
        }
        return '<section aria-label="Private HAFZATECH role review queue">'.
            '<h2>Staff role requests awaiting another administrator</h2>'.
            '<p>Role changes require an audited, independent MFA decision. '.
            'Only authorized reviewers may act on a requested role.</p>'.
            '<table><thead><tr><th>Request ID</th><th>Requester ID</th><th>Staff ID</th>'.
            '<th>Requested role</th><th>Scope</th><th>Justification</th>'.
            '<th>Created</th><th>Action</th>'.
            '</tr></thead><tbody>'.$body.'</tbody></table></section>';
    }

    private static function uuidV4():string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&15)|64);
        $bytes[8]=chr((ord($bytes[8])&63)|128);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.
            substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
