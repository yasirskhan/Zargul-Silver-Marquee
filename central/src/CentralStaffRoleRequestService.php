<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use PDOException;
use Throwable;

/**
 * INTERNAL immutable vendor role REQUEST only, never a role assignment.
 * A future independent MFA approver must decide in a separate transaction;
 * approval MUST recheck live roles, scope, dual-control and compatibility.
 */
final class CentralStaffRoleRequestService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffAccess $access,
        private readonly bool $enabled=false
    ) {}

    /** @return array{request_id:string,target_staff_id:string,role:string,state:string} */
    public function request(string $bearer,string $requestId,string $targetId,
        string $role,?string $customerScopeId,string $reason):array
    {
        if(!$this->enabled ||
            getenv('CENTRAL_STAFF_ROLE_REQUEST_ENABLED')!=='true') {
            throw new RegistrationError('staff_role_requests_not_enabled',503);
        }
        $reason=trim($reason);
        if(!self::uuid($requestId) || !self::uuid($targetId) ||
            ($customerScopeId!==null && !self::uuid($customerScopeId)) ||
            !array_key_exists($role,StaffRolePermissions::ROLE_PERMISSIONS) ||
            strlen($reason)<5 || strlen($reason)>500 ||
            str_contains($reason,"\0")) {
            throw new RegistrationError('invalid_staff_role_request',422);
        }
        // Vendor-wide administration and independent audit cannot be scoped
        // to arbitrary tenant identifiers to create ambiguous privileges.
        if($customerScopeId!==null &&
            in_array($role,['staff_administrator','super_administrator',
                'auditor'],true)) {
            throw new RegistrationError('invalid_staff_role_request',422);
        }
        $actor=$this->sessions->staffFor($bearer);
        if($actor===null || $actor===$targetId ||
            !$this->access->permits($actor,'staff.roles.assign',null,true)) {
            throw new RegistrationError('staff_role_request_denied');
        }
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare(
                'SELECT id,status FROM central_staff_users
                 WHERE id IN (:actor,:target) ORDER BY id FOR UPDATE'
            );
            $q->execute(['actor'=>$actor,'target'=>$targetId]);
            $people=array_column($q->fetchAll(PDO::FETCH_ASSOC),'status','id');
            if(count($people)!==2 || $people[$actor]!=='active' ||
                !in_array($people[$targetId],['active','pending_mfa'],true)) {
                throw new RegistrationError('staff_role_request_denied');
            }
            $q=$this->db->prepare(
                'SELECT role,customer_scope_id FROM central_staff_role_grants
                 WHERE staff_id=:actor AND revoked_at IS NULL
                 ORDER BY id FOR SHARE'
            );
            $q->execute(['actor'=>$actor]);
            $grants=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$this->access->permits(
                $actor,'staff.roles.assign',null,true)) {
                throw new RegistrationError('staff_role_request_denied');
            }
            $allowed=false;
            foreach($grants as $grant) {
                if($grant['customer_scope_id']===null &&
                    StaffRolePermissions::mayGrantRole(
                    $grant['role'],$role,false,true)) {
                    $allowed=true;
                    break;
                }
            }
            if(!$allowed)throw new RegistrationError('staff_role_request_denied');
            // This is only a proposal, but do not even stage a requested
            // combination that would violate billing/license segregation.
            $q=$this->db->prepare(
                'SELECT role FROM central_staff_role_grants
                 WHERE staff_id=:target AND revoked_at IS NULL
                 ORDER BY id FOR SHARE'
            );
            $q->execute(['target'=>$targetId]);
            if(!StaffRolePermissions::compatibleWith(
                $role,$q->fetchAll(PDO::FETCH_COLUMN))) {
                throw new RegistrationError('staff_role_request_denied');
            }


            if($customerScopeId!==null) {
                $q=$this->db->prepare(
                    "SELECT id FROM vendor_customers
                     WHERE id=:customer AND status='active'"
                );
                $q->execute(['customer'=>$customerScopeId]);
                if($q->fetchColumn()!==$customerScopeId) {
                    throw new RegistrationError('invalid_staff_role_request',422);
                }
            }
            $q=$this->db->prepare(
                'SELECT actor_staff_id,target_staff_id,role,
                        customer_scope_id,reason
                 FROM central_staff_role_requests WHERE id=:request FOR UPDATE'
            );
            $q->execute(['request'=>$requestId]);
            $prior=$q->fetch(PDO::FETCH_ASSOC);
            if($prior) {
                if($prior['actor_staff_id']!==$actor ||
                    $prior['target_staff_id']!==$targetId ||
                    $prior['role']!==$role ||
                    $prior['customer_scope_id']!==$customerScopeId ||
                    $prior['reason']!==$reason) {
                    throw new RegistrationError('staff_role_request_conflict',409);
                }
            } else {
                $q=$this->db->prepare(
                    'INSERT INTO central_staff_role_requests
                     (id,actor_staff_id,target_staff_id,role,customer_scope_id,reason)
                     VALUES(:id,:actor,:target,:role,:scope,:reason)'
                );
                $q->execute(['id'=>$requestId,'actor'=>$actor,'target'=>$targetId,
                    'role'=>$role,'scope'=>$customerScopeId,'reason'=>$reason]);
            }
            $this->db->commit();
            return ['request_id'=>$requestId,'target_staff_id'=>$targetId,
                'role'=>$role,'state'=>'pending_independent_approval'];
        } catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException && $e->getCode()==='23505') {
                throw new RegistrationError('staff_role_request_conflict',409);
            }
            throw $e;
        }
    }

    /** No target staff identity, credential or private reason in receipt. */
    public static function html(array $result):string
    {
        if(($result['state']??null)!=='pending_independent_approval' ||
            !is_string($result['request_id']??null) ||
            !self::uuid($result['request_id'])) {
            throw new RegistrationError('invalid_staff_role_request_result');
        }
        return '<section aria-label="Private HAFZATECH role request receipt">'.
            '<h2>Role proposal recorded</h2>'.
            '<p>This request has not granted any staff permission. '.
            'A different authorized MFA administrator must review it.</p>'.
            '<p>Request ID: '.
            htmlspecialchars($result['request_id'],
                ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').
            '</p></section>';
    }

    private static function uuid(string $id):bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $id)===1;
    }
}
