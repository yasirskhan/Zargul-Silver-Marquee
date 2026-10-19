<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use Throwable;

/**
 * INTERNAL, read-only prerequisite for supervised pending-staff enrollment.
 * Checks a cryptographic identity-provider assertion against the ACTUAL
 * pending central staff record and immutable provision provenance. It NEVER
 * verifies an account for activation, enrolls a password/MFA credential,
 * changes a role or permits login. It MUST NOT be mounted on an HTTP route.
 *
 * This is not a completed enrollment flow: a future operator-approved,
 * recipient-bound one-use challenge ledger and trusted out-of-band provider
 * are mandatory before any assertion can be consumed for activation. Passing
 * an arbitrary caller nonce is insufficient proof of recipient identity.
 */
final class CentralStaffIdentityAttestationVerifier
{
    private const PURPOSE='HAFZATECH-STAFF-IDENTITY-ATTESTATION-V1';
    private const UUID='/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][0-9a-f]{3}-[a-f0-9]{12}$/D';

    public function __construct(
        private readonly PDO $db,
        private readonly string $providerKeyId,
        private readonly string $providerPublicKey,
        private readonly bool $enabled=false
    ) {}

    /**
     * Validate signed evidence against an internally supplied pending identity
     * and expected challenge nonce. The nonce MUST originate from a future
     * independently persisted, single-use recipient challenge, not HTTP input.
     * @param array<string,mixed> $envelope
     * @return array{staff_id:string,attestation_signature_valid:bool,activation_allowed:bool}
     */
    public function inspect(string $targetId,string $expectedNonce,
        array $envelope,int $nowUtc):array
    {
        if(!$this->enabled ||
           getenv('CENTRAL_STAFF_IDENTITY_ATTESTATION_ENABLED')!=='true') {
            throw new RegistrationError('staff_identity_attestation_not_enabled',503);
        }
        $deny=static function():never {
            throw new RegistrationError('invalid_staff_identity_attestation',422);
        };
        if(preg_match(self::UUID,$targetId)!==1 ||
           !self::isNonce($expectedNonce) ||
           $nowUtc<0 || strlen($this->providerPublicKey)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
           preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$this->providerKeyId)!==1 ||
           self::keys($envelope)!==['key_id','payload','signature'] ||
           ($envelope['key_id']??null)!==$this->providerKeyId ||
           !is_string($envelope['payload']??null) ||
           !is_string($envelope['signature']??null) ||
           strlen($envelope['payload'])>5464) {
            $deny();
        }
        $json=self::decodeB64($envelope['payload'],4096);
        $signature=self::decodeB64($envelope['signature'],64);
        if($json===null || $signature===null || strlen($signature)!==64 ||
           !sodium_crypto_sign_verify_detached(
               $signature,self::PURPOSE."\n".$json,$this->providerPublicKey)) {
            $deny();
        }
        try {
            $payload=json_decode($json,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($payload) ||
               json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)!==$json) {
                $deny();
            }
        } catch(Throwable $e) {
            $deny();
        }
        if(self::keys($payload)!==[
            'email_sha256','evidence_ref','expires_at','issued_at','nonce',
            'purpose','staff_id','version'
        ] ||
           ($payload['version']??null)!=='v1' ||
           ($payload['purpose']??null)!==self::PURPOSE ||
           ($payload['staff_id']??null)!==$targetId ||
           ($payload['nonce']??null)!==$expectedNonce ||
           !is_string($payload['email_sha256']??null) ||
           preg_match('/^[a-f0-9]{64}$/D',$payload['email_sha256'])!==1 ||
           !is_string($payload['evidence_ref']??null) ||
           preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$payload['evidence_ref'])!==1 ||
           !is_int($payload['issued_at']??null) ||
           !is_int($payload['expires_at']??null) ||
           $payload['issued_at']>$nowUtc ||
           $payload['issued_at']<$nowUtc-300 ||
           $payload['expires_at']<=$nowUtc ||
           $payload['expires_at']>$payload['issued_at']+300) {
            $deny();
        }
        // The assertion is meaningful only for an exact immutable sponsor-
        // provisioned PENDING staff record. No caller email or account-status
        // claim is accepted. A changed email invalidates earlier attestations.
        $q=$this->db->prepare(
            "SELECT u.email FROM central_staff_users u
             WHERE u.id=:id AND u.status='pending_mfa'
               AND EXISTS (SELECT 1 FROM central_staff_provision_events p
                           WHERE p.target_staff_id=u.id)"
        );
        $q->execute(['id'=>$targetId]);
        $email=$q->fetchColumn();
        if(!is_string($email) ||
           !hash_equals(hash('sha256',$email),$payload['email_sha256'])) {
            $deny();
        }
        return [
            'staff_id'=>$targetId,
            'attestation_signature_valid'=>true,
            'activation_allowed'=>false
        ];
    }

    /** @param array<mixed> $a @return array<int,string> */
    private static function keys(array $a):array
    {
        $keys=array_keys($a);
        sort($keys,SORT_STRING);
        return $keys;
    }

    private static function isNonce(string $nonce):bool
    {
        return self::decodeB64($nonce,32)!==null &&
            strlen((string)self::decodeB64($nonce,32))===32;
    }

    private static function decodeB64(string $encoded,int $max):?string
    {
        if($encoded==='' || strlen($encoded)>intdiv($max*4+2,3)+2 ||
           preg_match('/^[A-Za-z0-9_-]+$/D',$encoded)!==1) return null;
        $decoded=base64_decode(strtr($encoded,'-_','+/'),true);
        if(!is_string($decoded) || strlen($decoded)>$max ||
           rtrim(strtr(base64_encode($decoded),'+/','-_'),'=')!==$encoded) {
            return null;
        }
        return $decoded;
    }
}
