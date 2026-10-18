<?php
declare(strict_types=1);
namespace Hafzatech\Central;

use PDO;

/**
 * Internal only. Not routed to HTTP. The eventual vendor controller must
 * provide HTTPS, throttling, secure cookies, CSRF and KMS-backed MFA secrets.
 * Session identity never substitutes for per-action scoped authorization.
 */
final class CentralStaffSessionService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffCredentialVerifier $credentials
    ) {}

    public function login(string $email, string $password, string $totp): ?string
    {
        $staff=$this->credentials->verify($email,$password,$totp,time());
        if($staff===null)return null;
        $snapshot=$this->snapshot($staff);
        if($snapshot===null)return null;
        $token=bin2hex(random_bytes(32));
        $q=$this->db->prepare(
            "INSERT INTO central_staff_sessions
             (id,staff_id,token_hash,authorization_snapshot,expires_at,idle_until)
             VALUES(:id,:staff,decode(:digest,'hex'),:snapshot,
              now()+interval '12 hours',now()+interval '30 minutes')"
        );
        $q->execute(['id'=>self::uuid(),'staff'=>$staff,
            'digest'=>hash('sha256',$token),'snapshot'=>$snapshot]);
        return $token;
    }

    public function staffFor(string $token): ?string
    {
        $digest=self::digest($token);
        if($digest===null)return null;
        $q=$this->db->prepare(
            "SELECT id,staff_id,authorization_snapshot FROM central_staff_sessions
             WHERE token_hash=decode(:digest,'hex') AND revoked_at IS NULL
             AND expires_at>now() AND idle_until>now()"
        );
        $q->execute(['digest'=>$digest]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        $current=$this->snapshot($row['staff_id']);
        if($current===null || !hash_equals($row['authorization_snapshot'],$current)){
            $this->logout($token);
            return null;
        }
        $q=$this->db->prepare(
            "UPDATE central_staff_sessions SET idle_until=now()+interval '30 minutes'
             WHERE id=:id AND token_hash=decode(:digest,'hex') AND revoked_at IS NULL
             AND expires_at>now() AND idle_until>now() RETURNING staff_id"
        );
        $q->execute(['id'=>$row['id'],'digest'=>$digest]);
        $staff=$q->fetchColumn();
        return is_string($staff)?$staff:null;
    }

    public function rotate(string $token): ?string
    {
        if($this->staffFor($token)===null)return null;
        $new=bin2hex(random_bytes(32));
        $q=$this->db->prepare(
            "UPDATE central_staff_sessions SET token_hash=decode(:next,'hex')
             WHERE token_hash=decode(:old,'hex') AND revoked_at IS NULL
             AND expires_at>now() AND idle_until>now() RETURNING id"
        );
        $q->execute(['next'=>hash('sha256',$new),'old'=>self::digest($token)]);
        return $q->fetchColumn()===false?null:$new;
    }

    public function logout(string $token): bool
    {
        $digest=self::digest($token);
        if($digest===null)return false;
        $q=$this->db->prepare(
            "UPDATE central_staff_sessions SET revoked_at=now()
             WHERE token_hash=decode(:digest,'hex') AND revoked_at IS NULL
             RETURNING id"
        );
        $q->execute(['digest'=>$digest]);
        return $q->fetchColumn()!==false;
    }

    private function snapshot(string $staff): ?string
    {
        $q=$this->db->prepare(
            "SELECT password_hash,encode(mfa_secret_ciphertext,'hex') AS sealed
             FROM central_staff_users WHERE id=:id AND status='active'
             AND mfa_enrolled_at IS NOT NULL"
        );
        $q->execute(['id'=>$staff]);
        $identity=$q->fetch(PDO::FETCH_ASSOC);
        if(!$identity || $identity['sealed']===null)return null;
        $q=$this->db->prepare(
            'SELECT id,role,customer_scope_id FROM central_staff_role_grants
             WHERE staff_id=:id AND revoked_at IS NULL ORDER BY id'
        );
        $q->execute(['id'=>$staff]);
        $roles=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$roles)return null;
        $names=array_column($roles,'role');
        if(in_array('billing_officer',$names,true) &&
           in_array('license_officer',$names,true))return null;
        return hash('sha256',json_encode([$identity,$roles],JSON_THROW_ON_ERROR));
    }

    private static function digest(string $token): ?string
    {
        return preg_match('/^[a-f0-9]{64}$/D',$token)
            ?hash('sha256',$token):null;
    }

    private static function uuid(): string
    {
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).
            '-'.substr($h,16,4).'-'.substr($h,20);
    }
}
