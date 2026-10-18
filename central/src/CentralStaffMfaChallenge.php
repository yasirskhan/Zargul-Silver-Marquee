<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * INTERNAL trusted vendor staff MFA verification step. No route, login,
 * session, password verifier or authorization grant is implemented here.
 * The caller MUST separately verify a real password in a protected server
 * session, derive staff ID there, use trusted server time, and restrict IP
 * and user-attempt rates before calling this service.
 */
final class CentralStaffMfaChallenge
{
    /** @param Closure(string):string $decryptProtectedSecret */
    public function __construct(
        private readonly PDO $db,
        private readonly Closure $decryptProtectedSecret
    ) {}

    public function verify(string $staffId,string $code,
        bool $trustedPasswordStep,int $trustedServerUnixTime):bool
    {
        if(!$trustedPasswordStep || $trustedServerUnixTime<0 ||
           !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$staffId) ||
           !preg_match('/^[0-9]{6}$/D',$code))return false;
        $this->db->beginTransaction();
        try {
            // Serialize all attempts on the staff account row, including
            // first enrollment without an existing replay-state row.
            $q=$this->db->prepare(
                "SELECT id,status,mfa_enrolled_at,encode(mfa_secret_ciphertext,'hex') AS sealed
                 FROM central_staff_users WHERE id=:staff FOR UPDATE"
            );
            $q->execute(['staff'=>$staffId]);
            $staff=$q->fetch(PDO::FETCH_ASSOC);
            if(!$staff || $staff['status']!=='active' ||
               $staff['mfa_enrolled_at']===null || !is_string($staff['sealed'])){
                $this->db->commit();return false;
            }
            $q=$this->db->prepare(
                'SELECT last_accepted_counter,failed_attempts,locked_until
                 FROM central_staff_mfa_replay_state WHERE staff_id=:staff FOR UPDATE'
            );
            $q->execute(['staff'=>$staffId]);
            $state=$q->fetch(PDO::FETCH_ASSOC);
            $last=(int)($state['last_accepted_counter']??-1);
            $failed=(int)($state['failed_attempts']??0);
            $lock=$state['locked_until']??null;
            if($lock!==null && $trustedServerUnixTime<
                (new DateTimeImmutable($lock))->getTimestamp()){
                $this->db->commit();return false;
            }
            $cipher=hex2bin($staff['sealed']);
            if($cipher===false)throw new \RuntimeException('Invalid protected MFA ciphertext.');
            $raw=($this->decryptProtectedSecret)($cipher);
            if(!is_string($raw))throw new \RuntimeException('Protected MFA key unavailable.');
            // A completed lockout window starts a new bounded attempt set.
            if($lock!==null)$failed=0;
            $counter=CentralStaffTotpVerifier::matchCounter(
                $raw,$code,$trustedServerUnixTime,$last
            );
            if($counter===null){
                $failed=min(5,$failed+1);
                $locked=$failed>=5
                    ? gmdate('Y-m-d H:i:s+00',$trustedServerUnixTime+900)
                    : null;
                $this->save($staffId,$last,$failed,$locked);
                $this->db->commit();return false;
            }
            $this->save($staffId,$counter,0,null);
            $this->db->commit();return true;
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    private function save(string $staffId,int $counter,int $failed,?string $lock):void
    {
        $stmt=$this->db->prepare(
            "INSERT INTO central_staff_mfa_replay_state
             (staff_id,last_accepted_counter,failed_attempts,locked_until)
             VALUES(:staff,:counter,:fail,:locked)
             ON CONFLICT (staff_id) DO UPDATE
             SET last_accepted_counter=EXCLUDED.last_accepted_counter,
                 failed_attempts=EXCLUDED.failed_attempts,
                 locked_until=EXCLUDED.locked_until,
                 updated_at=now()"
        );
        $stmt->execute(['staff'=>$staffId,'counter'=>$counter,
            'fail'=>$failed,'locked'=>$lock]);
    }
}
