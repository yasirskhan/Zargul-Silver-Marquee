<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Finalizes an already prepared AND independently paid vendor-signed grant.
 * Does not possess, generate, export or load Ed25519 signing private keys.
 * The worker obtains the signed envelope from protected KMS/HSM custody,
 * and the supplied sealing callback encrypts it with a separate vault key.
 * Not accessible from central/public or any ordinary staff/customer route.
 */
final class SignedGrantFinalization
{
    /** @param Closure(string):string $seal */
    public function __construct(
        private readonly PDO $db,
        private readonly PreparedSignedGrantValidator $validator,
        private readonly Closure $seal
    ) {}

    /** @param array<string,mixed> $envelope
     * @return array{revision_id:string,license_id:string,revision:int,state:string}
     */
    public function finalize(string $revisionId,string $officerId,bool $trustedMfa,
        bool $authenticatedSignerWorker,array $envelope): array
    {
        if (getenv('CENTRAL_SIGNER_FINALIZATION_ENABLED')!=='true' ||
            !$authenticatedSignerWorker) {
            throw new RegistrationError('protected_signer_not_enabled');
        }
        if(!$trustedMfa)throw new RegistrationError('mfa_required');
        $this->db->beginTransaction();
        try {
            // Serializes paid-invoice mutations, license advances and double
            // worker deliveries before eligibility and signature validation.
            $lock=$this->db->prepare(
                "SELECT r.id,r.state
                 FROM central_paid_license_revisions r
                 JOIN central_vendor_invoices i ON i.id=r.invoice_id
                 JOIN central_licenses l ON l.id=r.license_id
                 WHERE r.id=:id FOR UPDATE OF i,l,r"
            );
            $lock->execute(['id'=>$revisionId]);
            $record=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$record||$record['state']!=='awaiting_isolated_signer') {
                throw new RegistrationError('revision_not_pending');
            }
            $verified=$this->validator->validate($revisionId,$officerId,$trustedMfa,$envelope);
            $json=json_encode($envelope,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            $sealed=($this->seal)($json);
            if(!is_string($sealed)||strlen($sealed)<48||str_contains($sealed,$json)){
                throw new RuntimeException('Signer worker did not seal the signed grant safely.');
            }
            $stmt=$this->db->prepare(
                "UPDATE central_paid_license_revisions
                 SET state='signed',signed_envelope_ciphertext=decode(:cipher,'hex')
                 WHERE id=:id AND state='awaiting_isolated_signer'"
            );
            $stmt->execute(['id'=>$revisionId,'cipher'=>bin2hex($sealed)]);
            if($stmt->rowCount()!==1)throw new RegistrationError('revision_not_pending');
            $stmt=$this->db->prepare(
                "INSERT INTO central_license_signature_events
                 (revision_id,license_id,authorized_officer_id,
                  signer_key_id,sealed_envelope_sha256)
                 VALUES(:revision,:license,:officer,:key_id,:hash)"
            );
            $stmt->execute([
                'revision'=>$revisionId,'license'=>$verified['license_id'],
                'officer'=>$officerId,'key_id'=>$envelope['key_id'],
                'hash'=>hash('sha256',$sealed),
            ]);
            $this->db->commit();
            return [
                'revision_id'=>$revisionId,
                'license_id'=>$verified['license_id'],
                'revision'=>$verified['revision'],
                'state'=>'signed',
            ];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }
}
