<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;
use PDO;

/**
 * Authenticated read-only delivery of a SEALED and already signed grant.
 * Does not issue or sign one. Independent default-OFF release gate; no public
 * endpoint calls this class until protected vault custody is provisioned.
 * InstallationStatusService authenticates request signature, 5-minute clock
 * and one-time nonce before anything signed is decrypted or returned.
 */
final class SignedGrantDeliveryService
{
    /** @param Closure(string):string $unseal
     * @param array<string,string> $trustedSigningKeys
     */
    public function __construct(
        private readonly PDO $db,
        private readonly InstallationStatusService $statusService,
        private readonly Closure $unseal,
        private readonly array $trustedSigningKeys
    ) {}

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function fetch(array $request): array
    {
        if(getenv('CENTRAL_GRANT_DELIVERY_ENABLED')!=='true'){
            throw new RegistrationError('grant_delivery_not_enabled',503);
        }
        $status=$this->statusService->check($request);
        $status['signed_grant']=null;
        if($status['status']!=='signed_revision_pending_delivery' ||
            $status['revision']<=(int)$request['known_revision']) return $status;

        $query=$this->db->prepare(
            "SELECT l.id AS license_id,l.customer_id,l.installation_id,
                    r.revision,encode(r.signed_envelope_ciphertext,'hex') AS cipher
             FROM central_licenses l
             JOIN central_paid_license_revisions r ON r.license_id=l.id
             WHERE l.customer_id=:customer AND l.installation_id=:installation
               AND r.revision=:rev AND r.state='signed'"
        );
        $query->execute([
            'customer'=>$request['customer_id'],
            'installation'=>$request['installation_id'],
            'rev'=>$status['revision'],
        ]);
        $record=$query->fetch(PDO::FETCH_ASSOC);
        if(!$record||!is_string($record['cipher'])||$record['cipher']===''){
            throw new RegistrationError('signed_grant_unavailable');
        }
        $sealed=hex2bin($record['cipher']);
        if($sealed===false)throw new RegistrationError('invalid_sealed_grant');
        $json=($this->unseal)($sealed);
        if(!is_string($json)||strlen($json)>25000){
            throw new RegistrationError('invalid_sealed_grant');
        }
        $envelope=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($envelope)||array_is_list($envelope)) {
            throw new RegistrationError('invalid_sealed_grant');
        }
        $fields=array_keys($envelope);sort($fields);
        if($fields!==['key_id','payload','signature']||
            !is_string($envelope['key_id'])||
            !isset($this->trustedSigningKeys[$envelope['key_id']])) {
            throw new RegistrationError('untrusted_grant_signer');
        }
        $public=self::decode($this->trustedSigningKeys[$envelope['key_id']],32);
        $payload=self::decode($envelope['payload'],null);
        $sig=self::decode($envelope['signature'],64);
        if(strlen($payload)>16384||!sodium_crypto_sign_verify_detached(
            $sig,'HAFZATECH-LICENSE-GRANT-V1'."\n".$payload,$public)){
            throw new RegistrationError('invalid_grant_signature');
        }
        $grant=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($grant)||($grant['issuer']??null)!=='HAFZATECH'||
            ($grant['product']??null)!=='VenueDesk'||
            ($grant['api_version']??null)!=='v1'||
            ($grant['license_id']??null)!==$record['license_id']||
            ($grant['customer_id']??null)!==$record['customer_id']||
            ($grant['installation_id']??null)!==$record['installation_id']||
            ($grant['revision']??null)!==(int)$record['revision']) {
            throw new RegistrationError('signed_grant_binding_mismatch');
        }
        // Transport only. The VenueDesk client independently pins signer keys,
        // checks domain/term/limits and requires a signed matching status receipt
        // before advancing a last-successful-online-license check.
        $status['signed_grant']=$envelope;
        return $status;
    }

    private static function decode(mixed $text,?int $length): string
    {
        if(!is_string($text)||!preg_match('/^[A-Za-z0-9_-]+$/D',$text)){
            throw new RegistrationError('invalid_grant_encoding');
        }
        $raw=base64_decode(strtr($text,'-_','+/'),true);
        if($raw===false||($length!==null&&strlen($raw)!==$length)||
            rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$text) {
            throw new RegistrationError('invalid_grant_encoding');
        }
        return $raw;
    }
}
