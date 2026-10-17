<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * Authentication-only online status foundation, NOT a license-signing,
 * revocation, remote-control or renewal API. Future signed grant delivery is
 * a separate protected step. Uses trusted server clock and registered key.
 */
final class InstallationStatusService
{
    public function __construct(private readonly PDO $db) {}

    /**
     * @param array<string,mixed> $input
     * @return array{api_version:string,status:string,registered_installation_id:string,revision:int,nonce:string}
     */
    public function check(array $input): array
    {
        foreach(['installation_id','customer_id','nonce','request_time','signature'] as $key){
            if(!is_string($input[$key]??null))throw new RegistrationError('invalid_request',422);
        }
        foreach(['installation_id','customer_id'] as $key){
            if(!self::uuid($input[$key]))throw new RegistrationError('invalid_identifier',422);
        }
        if(!is_int($input['known_revision']??null) || $input['known_revision']<0 ||
           $input['known_revision']>2147483647){
            throw new RegistrationError('invalid_revision',422);
        }
        $nonce=self::decode($input['nonce'],32);
        $signature=self::decode($input['signature'],SODIUM_CRYPTO_SIGN_BYTES);
        $when=$input['request_time'];
        if(!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$when)){
            throw new RegistrationError('invalid_timestamp',422);
        }
        $time=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$when,new DateTimeZone('UTC'));
        if(!$time || $time->format('Y-m-d\TH:i:s\Z')!==$when ||
           abs(time()-$time->getTimestamp())>300){
            throw new RegistrationError('invalid_timestamp',422);
        }

        $payload=implode("\n",[
            'HAFZATECH-INSTALL-CHECK-V1',
            $input['installation_id'],$input['customer_id'],
            $input['request_time'],$input['nonce'],(string)$input['known_revision'],
        ]);

        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare(
                "SELECT i.id, i.ed25519_public_key
                 FROM registered_installations i
                 JOIN vendor_customers c ON c.id=i.customer_id AND c.status='active'
                 WHERE i.id=:installation AND i.customer_id=:customer
                   AND i.status='active' FOR UPDATE OF i"
            );
            $q->execute(['installation'=>$input['installation_id'],'customer'=>$input['customer_id']]);
            $installation=$q->fetch(PDO::FETCH_ASSOC);
            if(!$installation)throw new RegistrationError('unauthorized_installation');
            $pub=self::decode($installation['ed25519_public_key'],SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
            if(!sodium_crypto_sign_verify_detached($signature,$payload,$pub)){
                throw new RegistrationError('invalid_installation_proof');
            }
            $q=$this->db->prepare(
                "INSERT INTO central_installation_status_checks
                 (installation_id,customer_id,nonce_sha256,known_revision)
                 VALUES(:installation,:customer,:nonce,:revision)"
            );
            try{
                $q->execute(['installation'=>$input['installation_id'],'customer'=>$input['customer_id'],
                    'nonce'=>hash('sha256',$nonce),'revision'=>$input['known_revision']]);
            }catch(PDOException $e){
                if($e->getCode()==='23505')throw new RegistrationError('replayed_check',409);
                throw $e;
            }
            // Do not leak unpublished or unsigned license revisions.
            $q=$this->db->prepare(
                "SELECT COALESCE(MAX(r.revision),0)
                 FROM central_licenses l
                 JOIN central_paid_license_revisions r ON r.license_id=l.id
                   AND r.state='signed'
                 WHERE l.installation_id=:installation AND l.customer_id=:customer"
            );
            $q->execute(['installation'=>$input['installation_id'],'customer'=>$input['customer_id']]);
            $signedRevision=(int)$q->fetchColumn();
            $this->db->commit();
            return [
                'api_version'=>'v1',
                'status'=>$signedRevision>0?'signed_revision_pending_delivery':'registered_pending_license',
                'registered_installation_id'=>$input['installation_id'],
                'nonce'=>$input['nonce'],
                'revision'=>$signedRevision,
            ];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    private static function uuid(string $v): bool{
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$v)===1;
    }

    private static function decode(string $value,int $bytes): string{
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$value))throw new RegistrationError('invalid_encoding',422);
        $raw=base64_decode(strtr($value,'-_','+/'),true);
        if($raw===false||strlen($raw)!==$bytes ||
           rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$value){
            throw new RegistrationError('invalid_encoding',422);
        }
        return $raw;
    }
}
