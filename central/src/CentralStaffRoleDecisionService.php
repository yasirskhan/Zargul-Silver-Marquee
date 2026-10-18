<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use PDOException;
use Throwable;

/**
 * INTERNAL separate live-MFA human approver for immutable vendor role requests.
 * Reviewer is NEVER the requester, recipient or a supplied staff ID. Scoped
 * staff roles are installed only after rechecking CURRENT issuer/reviewer
 * grants, role compatibility and target identity inside ONE transaction.
 * No HTTP route, customer login, finance settlement or license signer.
 */
final class CentralStaffRoleDecisionService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffAccess $access,
        private readonly bool $enabled=false
    ) {}

    /** @return array{request_id:string,outcome:string,grant_created:bool} */
    public function decide(string $approverBearer,string $requestId,
        string $decisionId,string $outcome,string $reason):array
    {
        if(!$this->enabled ||
            getenv('CENTRAL_STAFF_ROLE_DECISIONS_ENABLED')!=='true') {
            throw new RegistrationError('staff_role_decisions_not_enabled',503);
        }
        $reason=trim($reason);
        if(!self::uuid($requestId)||!self::uuid($decisionId) ||
            !in_array($outcome,['approved','rejected'],true) ||
            strlen($reason)<5||strlen($reason)>500 ||
            str_contains($reason,"\0")) {
            throw new RegistrationError('invalid_staff_role_decision',422);
        }
        $approver=$this->sessions->staffFor($approverBearer);
        if($approver===null ||
            !$this->access->permits(
                $approver,'staff.roles.assign',null,true)) {
            throw new RegistrationError('staff_role_decision_denied');
        }
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare(
                'SELECT actor_staff_id,target_staff_id,role,customer_scope_id
                 FROM central_staff_role_requests WHERE id=:id'
            );
            $q->execute(['id'=>$requestId]);
            $request=$q->fetch(PDO::FETCH_ASSOC);
            if(!$request)throw new RegistrationError('staff_role_request_not_found',404);
            $actor=$request['actor_staff_id'];
            $target=$request['target_staff_id'];
            if($approver===$actor || $approver===$target ||
                $actor===$target) {
                throw new RegistrationError('staff_role_decision_denied');
            }
            $q=$this->db->prepare(
                'SELECT id,status FROM central_staff_users
                 WHERE id IN (:actor,:approver,:target)
                 ORDER BY id FOR UPDATE'
            );
            $q->execute(['actor'=>$actor,'approver'=>$approver,'target'=>$target]);
            $statuses=array_column($q->fetchAll(PDO::FETCH_ASSOC),'status','id');
            if(count($statuses)!==3 ||
                $statuses[$approver]!=='active' ||
                ($outcome==='approved' &&
                    ($statuses[$actor]!=='active' ||
                    !in_array($statuses[$target],['pending_mfa','active'],true)))) {
                throw new RegistrationError('staff_role_decision_denied');
            }
            $q=$this->db->prepare(
                'SELECT staff_id,role FROM central_staff_role_grants
                 WHERE staff_id IN (:actor,:approver,:target)
                   AND revoked_at IS NULL
                 ORDER BY id FOR SHARE'
            );
            $q->execute(['actor'=>$actor,'approver'=>$approver,'target'=>$target]);
            $all=$q->fetchAll(PDO::FETCH_ASSOC);
            $roles=[];
            foreach($all as $row) {
                $roles[$row['staff_id']][]=$row['role'];
            }
            if(!$this->access->permits(
                    $approver,'staff.roles.assign',null,true) ||
                ($outcome==='approved' &&
                    !$this->access->permits(
                        $actor,'staff.roles.assign',null,true))) {
                throw new RegistrationError('staff_role_decision_denied');
            }
            foreach($outcome==='approved'?[$actor,$approver]:[$approver] as $staffId) {
                $capable=false;
                foreach($roles[$staffId]??[] as $role) {
                    if(StaffRolePermissions::mayGrantRole(
                        $role,$request['role'],false,true)) {
                        $capable=true;break;
                    }
                }
                if(!$capable)throw new RegistrationError('staff_role_decision_denied');
            }
            if($outcome==='approved' &&
                !StaffRolePermissions::compatibleWith(
                    $request['role'],$roles[$target]??[])) {
                throw new RegistrationError('staff_role_conflict',409);
            }
            if($outcome==='approved' &&
                $request['customer_scope_id']!==null) {
                $q=$this->db->prepare(
                    "SELECT id FROM vendor_customers
                     WHERE id=:customer AND status='active'"
                );
                $q->execute(['customer'=>$request['customer_scope_id']]);
                if($q->fetchColumn()!==$request['customer_scope_id']) {
                    throw new RegistrationError('staff_role_decision_denied');
                }
            }
            $q=$this->db->prepare(
                'SELECT id,approver_staff_id,outcome,reason,role_grant_id
                 FROM central_staff_role_decisions
                 WHERE request_id=:request FOR UPDATE'
            );
            $q->execute(['request'=>$requestId]);
            $previous=$q->fetch(PDO::FETCH_ASSOC);
            if($previous) {
                if($previous['id']!==$decisionId ||
                    $previous['approver_staff_id']!==$approver ||
                    $previous['outcome']!==$outcome ||
                    $previous['reason']!==$reason) {
                    throw new RegistrationError('staff_role_decision_conflict',409);
                }
                $this->db->commit();
                return self::result($requestId,$outcome);
            }
            $grant=null;
            if($outcome==='approved') {
                $grant=self::uuidV4();
                $q=$this->db->prepare(
                    'INSERT INTO central_staff_role_grants
                     (id,staff_id,role,customer_scope_id,
                      granted_by,approved_by,reason)
                     VALUES(:id,:target,:role,:scope,
                      :issuer,:approver,:reason)'
                );
                $q->execute(['id'=>$grant,'target'=>$target,
                    'role'=>$request['role'],
                    'scope'=>$request['customer_scope_id'],
                    'issuer'=>$actor,'approver'=>$approver,
                    'reason'=>'Approved request '.$requestId]);
            }
            $q=$this->db->prepare(
                'INSERT INTO central_staff_role_decisions
                 (id,request_id,approver_staff_id,outcome,reason,role_grant_id)
                 VALUES(:id,:request,:approver,:outcome,:reason,:grant)'
            );
            $q->execute(['id'=>$decisionId,'request'=>$requestId,
                'approver'=>$approver,'outcome'=>$outcome,
                'reason'=>$reason,'grant'=>$grant]);
            $this->db->commit();
            return self::result($requestId,$outcome);
        }catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException && $e->getCode()==='23505') {
                throw new RegistrationError('staff_role_decision_conflict',409);
            }
            throw $e;
        }
    }
    /** @return array{request_id:string,outcome:string,grant_created:bool} */
    private static function result(string $request,string $decision):array
    {
        return ['request_id'=>$request,'outcome'=>$decision,
            'grant_created'=>$decision==='approved'];
    }
    private static function uuid(string $value):bool
    {
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $value)===1;
    }
    private static function uuidV4():string
    {
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).
            '-'.substr($h,16,4).'-'.substr($h,20);
    }
}
