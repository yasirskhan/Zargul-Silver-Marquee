<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use Throwable;

/**
 * Internal, self-only password change. The caller must already possess a
 * vendor-only, password+MFA authenticated server session. Never expose this
 * directly to HTTP without same-origin CSRF, current-password throttling,
 * HTTPS, cookies and independent operator KMS/security signoff.
 */
final class CentralStaffOwnPasswordChange
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions
    ) {}

    public function change(string $sessionBearer,string $oldPassword,
        string $newPassword):bool
    {
        if(strlen($newPassword)<12 || strlen($newPassword)>1024 ||
           strlen($oldPassword)>1024 ||
           !preg_match('/^[0-9a-f]{64}$/D',$sessionBearer))return false;
        $staffId=$this->sessions->staffFor($sessionBearer);
        if($staffId===null)return false;
        $this->db->beginTransaction();
        try{
            // Lock the current account first to serialize password changes.
            $q=$this->db->prepare(
                'SELECT password_hash,status,mfa_enrolled_at
                 FROM central_staff_users WHERE id=:id FOR UPDATE'
            );
            $q->execute(['id'=>$staffId]);
            $staff=$q->fetch(PDO::FETCH_ASSOC);
            if(!$staff || $staff['status']!=='active' ||
               $staff['mfa_enrolled_at']===null ||
               !password_verify($oldPassword,$staff['password_hash']) ||
               password_verify($newPassword,$staff['password_hash'])){
                $this->db->rollBack();return false;
            }
            $q=$this->db->prepare(
                "SELECT id FROM central_staff_sessions
                 WHERE staff_id=:staff AND token_hash=decode(:digest,'hex')
                 AND revoked_at IS NULL AND expires_at>now() AND idle_until>now()
                 FOR UPDATE"
            );
            $q->execute(['staff'=>$staffId,'digest'=>hash('sha256',$sessionBearer)]);
            if($q->fetchColumn()===false){
                $this->db->rollBack();return false;
            }
            $newHash=password_hash($newPassword,PASSWORD_ARGON2ID);
            if(!is_string($newHash))throw new \RuntimeException('Password hashing unavailable');
            $q=$this->db->prepare(
                'UPDATE central_staff_users SET password_hash=:hash,updated_at=now()
                 WHERE id=:staff AND status=\'active\''
            );
            $q->execute(['hash'=>$newHash,'staff'=>$staffId]);
            if($q->rowCount()!==1){
                $this->db->rollBack();return false;
            }
            $q=$this->db->prepare(
                "WITH revoked AS (
                    UPDATE central_staff_sessions SET revoked_at=now()
                    WHERE staff_id=:staff AND revoked_at IS NULL
                    RETURNING id,staff_id
                 )
                 INSERT INTO central_staff_session_events(session_id,staff_id,outcome)
                 SELECT id,staff_id,'invalidated' FROM revoked"
            );
            $q->execute(['staff'=>$staffId]);
            $q=$this->db->prepare(
                "INSERT INTO central_staff_password_change_events(staff_id,outcome)
                 VALUES(:staff,'changed')"
            );
            $q->execute(['staff'=>$staffId]);
            $this->db->commit();
            return true;
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }
}
