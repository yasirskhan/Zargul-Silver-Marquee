<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use Throwable;

/**
 * INTERNAL HAFZATECH staff suspension. Does not expose an HTTP route or
 * create roles. Actor identity and staff.disable capability are both read
 * from the actual centrally MFA-authenticated server session.
 *
 * Super Administrators, self-action, forged bearer and already suspended
 * identities are denied. Account change, session revocation and immutable
 * account/session audit are committed together.
 */
final class CentralStaffSuspensionService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    public function suspend(string $actorBearer,string $targetStaffId,string $reason):bool
    {
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $targetStaffId) ||
           strlen(trim($reason))<5 || strlen(trim($reason))>500)return false;
        $actor=$this->sessions->staffFor($actorBearer);
        if($actor===null || $actor===$targetStaffId)return false;
        // Stable vendor staff row lock order prevents reciprocal admin actions
        // from needlessly deadlocking. Recheck authorization under these locks.
        $this->db->beginTransaction();
        try{
            $lock=$this->db->prepare(
                'SELECT id,status FROM central_staff_users
                 WHERE id IN (:actor,:target) ORDER BY id FOR UPDATE'
            );
            $lock->execute(['actor'=>$actor,'target'=>$targetStaffId]);
            $rows=$lock->fetchAll(PDO::FETCH_ASSOC);
            $statuses=array_column($rows,'status','id');
            if(count($statuses)!==2 || $statuses[$actor]!=='active' ||
               $statuses[$targetStaffId]!=='active'){
                $this->db->rollBack();return false;
            }
            $grantLocks=$this->db->prepare(
                'SELECT id FROM central_staff_role_grants
                 WHERE staff_id=:actor AND revoked_at IS NULL
                 ORDER BY id FOR SHARE'
            );
            $grantLocks->execute(['actor'=>$actor]);
            $grantLocks->fetchAll(PDO::FETCH_ASSOC);
            if(!$this->authorization->permits($actorBearer,'staff.disable',null)){
                $this->db->rollBack();return false;
            }
            $targetRoles=$this->db->prepare(
                'SELECT role FROM central_staff_role_grants
                 WHERE staff_id=:target AND revoked_at IS NULL
                 ORDER BY id FOR SHARE'
            );
            $targetRoles->execute(['target'=>$targetStaffId]);
            if(in_array('super_administrator',$targetRoles->fetchAll(PDO::FETCH_COLUMN),true)){
                $this->db->rollBack();return false;
            }
            $suspend=$this->db->prepare(
                "UPDATE central_staff_users
                 SET status='suspended',updated_at=now()
                 WHERE id=:target AND status='active'"
            );
            $suspend->execute(['target'=>$targetStaffId]);
            if($suspend->rowCount()!==1){
                $this->db->rollBack();return false;
            }
            $event=$this->db->prepare(
                "INSERT INTO central_staff_account_actions
                 (actor_staff_id,target_staff_id,action,previous_status,reason)
                 VALUES(:actor,:target,'suspended','active',:reason)"
            );
            $event->execute(['actor'=>$actor,'target'=>$targetStaffId,
                'reason'=>trim($reason)]);
            $revoke=$this->db->prepare(
                "WITH revoked AS (
                    UPDATE central_staff_sessions SET revoked_at=now()
                    WHERE staff_id=:target AND revoked_at IS NULL
                    RETURNING id,staff_id
                 )
                 INSERT INTO central_staff_session_events(session_id,staff_id,outcome)
                 SELECT id,staff_id,'invalidated' FROM revoked"
            );
            $revoke->execute(['target'=>$targetStaffId]);
            $this->db->commit();
            return true;
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }
}
