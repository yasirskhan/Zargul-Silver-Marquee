<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use PDOException;
use Throwable;

/**
 * INTERNAL, independently default-OFF HAFZATECH staff identity preparation.
 * The new staff user is ALWAYS pending_mfa, with an unknowable random
 * Argon2id password and NO role grants, invitation, credentials or active
 * session. Requires separate audited operator-led enrollment later.
 *
 * Only a currently central-MFA-authenticated Staff Administrator with a live
 * GLOBAL staff.create capability may stage another identity. This service
 * cannot elevate its own actor, grant roles, enable login, or email tokens.
 */
final class CentralStaffProvisioningService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffAccess $access,
        private readonly bool $enabled=false
    ) {}

    /** @return array{staff_id:string,email:string,status:string,activation_ready:bool} */
    public function createPending(string $staffBearer,string $email,
        string $displayName,string $reason):array
    {
        if(!$this->enabled ||
            getenv('CENTRAL_STAFF_PROVISIONING_ENABLED')!=='true') {
            throw new RegistrationError('staff_provisioning_not_enabled',503);
        }
        $email=strtolower(trim($email));
        $displayName=trim($displayName);
        $reason=trim($reason);
        if(strlen($email)<5 || strlen($email)>254 ||
            filter_var($email,FILTER_VALIDATE_EMAIL)===false ||
            strlen($displayName)<2 || strlen($displayName)>100 ||
            strlen($reason)<5 || strlen($reason)>500 ||
            str_contains($displayName,"\0") || str_contains($reason,"\0")) {
            throw new RegistrationError('invalid_staff_provision_request',422);
        }
        $actor=$this->sessions->staffFor($staffBearer);
        if($actor===null ||
            !$this->access->permits($actor,'staff.create',null,true)) {
            throw new RegistrationError('staff_provision_denied');
        }
        $this->db->beginTransaction();
        try {
            $actorRow=$this->db->prepare(
                'SELECT id,status FROM central_staff_users
                 WHERE id=:actor FOR UPDATE'
            );
            $actorRow->execute(['actor'=>$actor]);
            if($actorRow->fetchColumn()!==$actor) {
                throw new RegistrationError('staff_provision_denied');
            }
            $grants=$this->db->prepare(
                'SELECT id FROM central_staff_role_grants
                 WHERE staff_id=:actor AND revoked_at IS NULL
                 ORDER BY id FOR SHARE'
            );
            $grants->execute(['actor'=>$actor]);
            $grants->fetchAll(PDO::FETCH_ASSOC);
            if(!$this->access->permits($actor,'staff.create',null,true)) {
                throw new RegistrationError('staff_provision_denied');
            }
            $existing=$this->db->prepare(
                'SELECT id,status,display_name FROM central_staff_users
                 WHERE email=:email FOR UPDATE'
            );
            $existing->execute(['email'=>$email]);
            $found=$existing->fetch(PDO::FETCH_ASSOC);
            if($found) {
                // Exact retry by original issuer on a still-unactivated,
                // unassigned placeholder is idempotent; never take over an
                // existing active or differently sponsored vendor account.
                $event=$this->db->prepare(
                    'SELECT actor_staff_id,reason
                     FROM central_staff_provision_events
                     WHERE target_staff_id=:target'
                );
                $event->execute(['target'=>$found['id']]);
                $prior=$event->fetch(PDO::FETCH_ASSOC);
                $roles=$this->db->prepare(
                    'SELECT count(*) FROM central_staff_role_grants
                     WHERE staff_id=:target'
                );
                $roles->execute(['target'=>$found['id']]);
                if($found['status']==='pending_mfa' &&
                   $found['display_name']===$displayName && $prior &&
                   $prior['actor_staff_id']===$actor &&
                   $prior['reason']===$reason &&
                   (int)$roles->fetchColumn()===0) {
                    $this->db->commit();
                    return self::result($found['id'],$email);
                }
                throw new RegistrationError('staff_account_already_exists',409);
            }
            $id=self::uuid();
            $unknown=bin2hex(random_bytes(32));
            $hash=password_hash($unknown,PASSWORD_ARGON2ID);
            if(!is_string($hash)) {
                throw new \RuntimeException('Unable to hash provisional secret');
            }
            $insert=$this->db->prepare(
                "INSERT INTO central_staff_users
                 (id,email,display_name,password_hash,status)
                 VALUES(:id,:email,:name,:hash,'pending_mfa')"
            );
            $insert->execute(['id'=>$id,'email'=>$email,
                'name'=>$displayName,'hash'=>$hash]);
            $audit=$this->db->prepare(
                "INSERT INTO central_staff_provision_events
                 (actor_staff_id,target_staff_id,action,reason)
                 VALUES(:actor,:target,'created_pending_mfa',:reason)"
            );
            $audit->execute(['actor'=>$actor,'target'=>$id,'reason'=>$reason]);
            $this->db->commit();
            return self::result($id,$email);
        }catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException && $e->getCode()==='23505') {
                throw new RegistrationError('staff_account_already_exists',409);
            }
            throw $e;
        }
    }

    /** Only safe pending-account identity metadata, NEVER credentials. */
    public static function html(array $record):string
    {
        if(($record['status']??null)!=='pending_mfa' ||
            ($record['activation_ready']??null)!==false ||
            !is_string($record['staff_id']??null) ||
            !is_string($record['email']??null)) {
            throw new RegistrationError('invalid_staff_provision_receipt');
        }
        $e=static fn(string $value):string=>htmlspecialchars(
            $value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return '<section aria-label="Private HAFZATECH staff pending account">'.
            '<h2>Pending staff identity prepared</h2>'.
            '<p>Not activated. No login, MFA enrollment, invitation or '.
            'role assignment was created.</p><dl>'.
            '<dt>Staff ID</dt><dd>'.$e($record['staff_id']).'</dd>'.
            '<dt>Email</dt><dd>'.$e($record['email']).'</dd>'.
            '</dl></section>';
    }

    private static function result(string $id,string $email):array
    {
        return ['staff_id'=>$id,'email'=>$email,
            'status'=>'pending_mfa','activation_ready'=>false];
    }

    private static function uuid():string
    {
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).
            '-'.substr($h,16,4).'-'.substr($h,20);
    }
}
