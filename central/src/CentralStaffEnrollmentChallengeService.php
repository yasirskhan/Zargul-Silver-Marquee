<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use Throwable;

/**
 * INTERNAL one-use-candidate nonce issuer for a future supervised enrollment
 * ceremony. There is no public route, message delivery, identity attestation
 * redemption, MFA enrollment, password setting, or account activation here.
 *
 * A nonce is public challenge material, NOT recipient-identity proof. Only
 * an independently trusted, recipient-controlled, external provider can
 * attest to a challenge in a separately reviewed flow. Records are immutable
 * and do not imply verified identity.
 */
final class CentralStaffEnrollmentChallengeService
{
    private const UUID='/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][0-9a-f]{3}-[a-f0-9]{12}$/D';

    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffAccess $access,
        private readonly string $approvedProviderKeyId,
        private readonly bool $enabled=false
    ) {}

    /** @return array{challenge_id:string,staff_id:string,nonce:string,activation_allowed:bool} */
    public function issue(string $staffBearer,string $targetId):array
    {
        if(!$this->enabled ||
            getenv('CENTRAL_STAFF_ENROLLMENT_CHALLENGE_ENABLED')!=='true') {
            throw new RegistrationError('staff_enrollment_challenge_not_enabled',503);
        }
        if(preg_match(self::UUID,$targetId)!==1 ||
           preg_match('/^[A-Za-z0-9_-]{1,64}$/D',
               $this->approvedProviderKeyId)!==1) {
            throw new RegistrationError('invalid_staff_enrollment_challenge',422);
        }
        $actor=$this->sessions->staffFor($staffBearer);
        if($actor===null || $actor===$targetId ||
            !$this->access->permits($actor,'staff.create',null,true)) {
            throw new RegistrationError('staff_enrollment_challenge_denied');
        }
        $this->db->beginTransaction();
        try {
            // Lock in a stable order and recheck CURRENT issuer permissions
            // so a concurrently revoked administrator cannot issue a nonce.
            $lockActor=$this->db->prepare(
                'SELECT id FROM central_staff_users WHERE id=:id FOR UPDATE');
            $lockActor->execute(['id'=>$actor]);
            if($lockActor->fetchColumn()!==$actor) {
                throw new RegistrationError('staff_enrollment_challenge_denied');
            }
            $grants=$this->db->prepare(
                'SELECT id FROM central_staff_role_grants
                 WHERE staff_id=:id AND revoked_at IS NULL ORDER BY id FOR SHARE');
            $grants->execute(['id'=>$actor]);
            $grants->fetchAll(PDO::FETCH_ASSOC);
            if(!$this->access->permits($actor,'staff.create',null,true)) {
                throw new RegistrationError('staff_enrollment_challenge_denied');
            }
            $target=$this->db->prepare(
                "SELECT u.email FROM central_staff_users u
                 WHERE u.id=:id AND u.status='pending_mfa'
                   AND EXISTS (SELECT 1 FROM central_staff_provision_events p
                               WHERE p.target_staff_id=u.id)
                 FOR UPDATE OF u");
            $target->execute(['id'=>$targetId]);
            $email=$target->fetchColumn();
            if(!is_string($email)) {
                throw new RegistrationError('staff_enrollment_challenge_unavailable',404);
            }
            $open=$this->db->prepare(
                'SELECT id FROM central_staff_enrollment_challenges
                 WHERE target_staff_id=:id AND expires_at>now()
                 ORDER BY id LIMIT 1');
            $open->execute(['id'=>$targetId]);
            if($open->fetchColumn()!==false) {
                throw new RegistrationError('staff_enrollment_challenge_pending',409);
            }
            // The target row remains locked until commit, serializing
            // concurrent issuers. Allow at most five challenge issues per
            // pending recipient in a rolling 24-hour period, INCLUDING
            // expired entries. Avoid brute-force resends and audit floods.
            $recent=$this->db->prepare(
                "SELECT count(*) FROM central_staff_enrollment_challenges
                 WHERE target_staff_id=:id
                   AND issued_at>now()-interval '24 hours'"
            );
            $recent->execute(['id'=>$targetId]);
            if((int)$recent->fetchColumn()>=5) {
                throw new RegistrationError(
                    'staff_enrollment_challenge_rate_limited',429);
            }
            $nonce=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $id=self::uuid();
            $q=$this->db->prepare(
                'INSERT INTO central_staff_enrollment_challenges
                 (id,target_staff_id,issued_by_staff_id,provider_key_id,
                  email_sha256,nonce_sha256,expires_at)
                 VALUES(:id,:target,:actor,:provider,:email,:nonce,
                        now()+interval '."'5 minutes'".')'
            );
            $q->execute(['id'=>$id,'target'=>$targetId,'actor'=>$actor,
                'provider'=>$this->approvedProviderKeyId,
                'email'=>hash('sha256',$email),
                'nonce'=>hash('sha256',$nonce)]);
            $this->db->commit();
            return ['challenge_id'=>$id,'staff_id'=>$targetId,
                'nonce'=>$nonce,'activation_allowed'=>false];
        } catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    private static function uuid():string
    {
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);
        $hex=bin2hex($b);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.
            substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
