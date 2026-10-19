<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use PDOException;
use Throwable;

/**
 * INTERNAL evidence-check receipt only. Requires a second globally authorized
 * MFA staff administrator, an unexpired immutable challenge, pinned provider
 * Ed25519 signature and a pending/provenanced EXACT recipient. Never sets an
 * account identity_verified flag, enrolls passwords/MFA, grants roles or
 * activates staff. Real recipient delivery/provider operations are absent.
 */
final class CentralStaffEnrollmentEvidenceService
{
    private const UUID='/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][0-9a-f]{3}-[a-f0-9]{12}$/D';

    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffAccess $access,
        private readonly CentralStaffIdentityAttestationVerifier $verifier,
        private readonly bool $enabled=false
    ) {}

    /** @param array<string,mixed> $attestation
     * @return array{evidence_check_id:string,staff_id:string,activation_allowed:bool}
     */
    public function checkAndRecord(string $reviewerBearer,string $challengeId,
        string $nonce,array $attestation):array
    {
        if(!$this->enabled ||
           getenv('CENTRAL_STAFF_ENROLLMENT_EVIDENCE_ENABLED')!=='true') {
            throw new RegistrationError('staff_enrollment_evidence_not_enabled',503);
        }
        if(preg_match(self::UUID,$challengeId)!==1 ||
           !is_string($attestation['payload']??null) ||
           !is_string($attestation['signature']??null)) {
            throw new RegistrationError('invalid_staff_enrollment_evidence',422);
        }
        $reviewer=$this->sessions->staffFor($reviewerBearer);
        if($reviewer===null ||
           !$this->access->permits($reviewer,'staff.create',null,true)) {
            throw new RegistrationError('staff_enrollment_evidence_denied');
        }
        $this->db->beginTransaction();
        try {
            // Challenge journal rows are immutable. Resolve issuer/target
            // before acquiring staff locks, then lock both administrators in
            // deterministic order. A concurrent role revocation must not
            // turn a previously checked role into an accepted audit receipt.
            $identity=$this->db->prepare(
                'SELECT target_staff_id,issued_by_staff_id
                 FROM central_staff_enrollment_challenges WHERE id=:id');
            $identity->execute(['id'=>$challengeId]);
            $identities=$identity->fetch(PDO::FETCH_ASSOC);
            if(!$identities) {
                throw new RegistrationError('staff_enrollment_evidence_unavailable',404);
            }
            $target=$identities['target_staff_id'];
            $issuer=$identities['issued_by_staff_id'];
            if($reviewer===$target || $reviewer===$issuer) {
                throw new RegistrationError('staff_enrollment_evidence_denied');
            }
            $authorizers=[$reviewer,$issuer];
            sort($authorizers,SORT_STRING);
            $lockStaff=$this->db->prepare(
                'SELECT id FROM central_staff_users WHERE id=:id FOR UPDATE');
            $grants=$this->db->prepare(
                'SELECT id FROM central_staff_role_grants
                 WHERE staff_id=:id AND revoked_at IS NULL ORDER BY id FOR SHARE');
            foreach($authorizers as $staffId) {
                $lockStaff->execute(['id'=>$staffId]);
                if($lockStaff->fetchColumn()!==$staffId) {
                    throw new RegistrationError('staff_enrollment_evidence_denied');
                }
                $grants->execute(['id'=>$staffId]);
                $grants->fetchAll(PDO::FETCH_ASSOC);
                if(!$this->access->permits($staffId,'staff.create',null,true)) {
                    throw new RegistrationError('staff_enrollment_evidence_denied');
                }
            }
            $targetLock=$this->db->prepare(
                "SELECT u.email FROM central_staff_users u
                 WHERE u.id=:id AND u.status='pending_mfa'
                   AND EXISTS (SELECT 1 FROM central_staff_provision_events p
                               WHERE p.target_staff_id=u.id)
                 FOR UPDATE OF u");
            $targetLock->execute(['id'=>$target]);
            $email=$targetLock->fetchColumn();
            if(!is_string($email)) {
                throw new RegistrationError('staff_enrollment_evidence_unavailable',404);
            }
            $challenge=$this->db->prepare(
                'SELECT target_staff_id,issued_by_staff_id,provider_key_id,
                        email_sha256,nonce_sha256
                 FROM central_staff_enrollment_challenges
                 WHERE id=:id AND expires_at>now() FOR UPDATE');
            $challenge->execute(['id'=>$challengeId]);
            $claim=$challenge->fetch(PDO::FETCH_ASSOC);
            if(!$claim ||
               $claim['target_staff_id']!==$target ||
               $claim['issued_by_staff_id']!==$issuer) {
                throw new RegistrationError('staff_enrollment_evidence_denied');
            }
            if(!hash_equals($claim['email_sha256'],hash('sha256',$email)) ||
               !hash_equals($claim['nonce_sha256'],hash('sha256',$nonce)) ||
               ($attestation['key_id']??null)!==$claim['provider_key_id']) {
                throw new RegistrationError('invalid_staff_enrollment_evidence',422);
            }
            $existing=$this->db->prepare(
                'SELECT id FROM central_staff_enrollment_evidence_checks
                 WHERE challenge_id=:id');
            $existing->execute(['id'=>$challengeId]);
            if($existing->fetchColumn()!==false) {
                throw new RegistrationError('staff_enrollment_evidence_already_checked',409);
            }
            $verified=$this->verifier->inspect($target,$nonce,$attestation,time());
            if($verified['activation_allowed']!==false ||
               $verified['attestation_signature_valid']!==true) {
                throw new RegistrationError('invalid_staff_enrollment_evidence',422);
            }
            $id=self::uuid();
            $record=$this->db->prepare(
                "INSERT INTO central_staff_enrollment_evidence_checks
                 (id,challenge_id,reviewed_by_staff_id,signature_sha256,decision)
                 VALUES(:id,:challenge,:reviewer,:digest,'signature_checked_only')");
            $record->execute(['id'=>$id,'challenge'=>$challengeId,
                'reviewer'=>$reviewer,
                'digest'=>hash('sha256',$attestation['payload']."\n".
                    $attestation['signature'])]);
            $this->db->commit();
            return ['evidence_check_id'=>$id,'staff_id'=>$target,
                'activation_allowed'=>false];
        } catch(Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException && $e->getCode()==='23505') {
                throw new RegistrationError('staff_enrollment_evidence_already_checked',409);
            }
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
