<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * INTERNAL pre-auth throttle. No raw IP/email is stored. The HMAC key must
 * be injected from an operator secret manager, not client config or source.
 * Reverse-proxy/WAF limits and secure network client-IP provenance are still
 * mandatory before exposing any vendor login route.
 */
final class CentralStaffLoginRateLimiter
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $protectedHmacKey
    ) {
        if(strlen($protectedHmacKey)<32)
            throw new \InvalidArgumentException('Protected login throttle key required');
    }

    /** Atomically reserves an attempt; 5 per account or 30 per trusted IP / 15m. */
    public function reserve(string $email,string $trustedRemoteIp): bool
    {
        $ip=filter_var($trustedRemoteIp,FILTER_VALIDATE_IP);
        if($ip===false)return false;
        $account=strtolower(trim($email));
        if(strlen($account)>254 || $account==='')$account='invalid';
        $keys=[
            $this->key('account',$account)=>5,
            $this->key('remote',bin2hex(inet_pton($ip)))=>30
        ];
        ksort($keys,SORT_STRING); // Stable lock order across overlapping requests.
        $this->db->beginTransaction();
        try{
            $insert=$this->db->prepare(
                "INSERT INTO central_staff_login_rate_limits(key_hash)
                 VALUES(decode(:digest,'hex')) ON CONFLICT DO NOTHING"
            );
            foreach($keys as $digest=>$limit)$insert->execute(['digest'=>$digest]);
            $clock=$this->db->query('SELECT now()')->fetchColumn();
            $now=new DateTimeImmutable($clock);
            $window=$now->modify('-15 minutes');
            $until=$now->modify('+15 minutes')->format('Y-m-d H:i:sP');
            $select=$this->db->prepare(
                "SELECT attempt_count,window_started,blocked_until
                 FROM central_staff_login_rate_limits
                 WHERE key_hash=decode(:digest,'hex') FOR UPDATE"
            );
            $update=$this->db->prepare(
                "UPDATE central_staff_login_rate_limits
                 SET attempt_count=:attempts,window_started=:started,
                     blocked_until=:locked,changed_at=now()
                 WHERE key_hash=decode(:digest,'hex')"
            );
            foreach($keys as $digest=>$limit){
                $select->execute(['digest'=>$digest]);
                $row=$select->fetch(PDO::FETCH_ASSOC);
                if(!$row)throw new \RuntimeException('Missing rate-limit row');
                $blocked=$row['blocked_until']===null ? null
                    : new DateTimeImmutable($row['blocked_until']);
                if($blocked!==null && $blocked>$now){
                    $this->db->rollBack();return false;
                }
                $started=new DateTimeImmutable($row['window_started']);
                $reset=$started<=$window;
                $attempts=$reset?1:((int)$row['attempt_count']+1);
                if($attempts>$limit){
                    $this->db->rollBack();return false;
                }
                $update->execute([
                    'attempts'=>$attempts,
                    'started'=>$reset?$clock:$row['window_started'],
                    'locked'=>$attempts===$limit?$until:null,
                    'digest'=>$digest
                ]);
            }
            $this->db->commit();
            return true;
        }catch(Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
    }

    /** Only called by successful verified password+TOTP session issuance. */
    public function clearSuccessfulAccount(string $email): void
    {
        $account=strtolower(trim($email));
        $q=$this->db->prepare(
            "DELETE FROM central_staff_login_rate_limits WHERE key_hash=decode(:digest,'hex')"
        );
        $q->execute(['digest'=>$this->key('account',$account)]);
    }

    private function key(string $scope,string $value):string
    {
        return hash_hmac('sha256',$scope."\0".$value,$this->protectedHmacKey);
    }
}
