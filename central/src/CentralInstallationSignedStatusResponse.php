<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;

/**
 * INTERNAL composer for a central installation status with its matching
 * isolated-worker Ed25519 receipt. NO HTTP route, signing private key,
 * customer credential or online-license entitlement lives in this class.
 *
 * Production construction requires an operator-provisioned authenticated
 * KMS/HSM worker, trusted signer public keys and an independent release gate.
 * Never pass an HTTP-supplied callback, enabled flag or public key here.
 */
final class CentralInstallationSignedStatusResponse
{
    /** @param Closure(array<string,mixed>):array<string,mixed> $isolatedSigner
     * @param array<string,string> $trustedPublicKeys Base64url Ed25519 keys
     */
    public function __construct(
        private readonly InstallationStatusService $statusService,
        private readonly Closure $isolatedSigner,
        private readonly array $trustedPublicKeys,
        private readonly bool $enabled=false,
        private readonly ?SignedGrantDeliveryService $grantDelivery=null
    ) {}

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function check(array $request): array
    {
        // Do not consume installation nonces until a separately released,
        // pinned, operator-provisioned signing boundary is configured.
        if (!$this->enabled || getenv('CENTRAL_SIGNED_STATUS_ENABLED')!=='true'
            || $this->trustedPublicKeys===[]) {
            throw new RegistrationError('signed_status_not_enabled',503);
        }

        // Exactly one identity, clock-skew, nonce replay, customer-scope and
        // rate-limit check. The signed receipt cannot authenticate a forged
        // or previously used installation request.
        // If a separate operator-approved sealed-grant reader is configured,
        // it verifies the installation request exactly once before returning
        // an already signed revision. Never call statusService twice: doing
        // so would replay the same nonce and invalidate the renewal check.
        // The delivery service has its own default-OFF release gate.
        $status=$this->grantDelivery===null
            ? $this->statusService->check($request)
            : $this->grantDelivery->fetch($request);
        $claim=[
            'api_version'=>'v1','issuer'=>'HAFZATECH',
            'installation_id'=>$status['registered_installation_id'],
            'customer_id'=>$request['customer_id'],
            'nonce'=>$status['nonce'],
            'status'=>$status['status'],
            'revision'=>$status['revision'],
            'issued_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $raw=json_encode($claim,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $envelope=($this->isolatedSigner)($claim);
        if(!is_array($envelope) || array_is_list($envelope)) {
            throw new RegistrationError('invalid_signed_status_receipt');
        }
        $fields=array_keys($envelope);sort($fields);
        if($fields!==['key_id','payload','signature'] ||
           !is_string($envelope['key_id']??null) ||
           !is_string($envelope['payload']??null) ||
           !is_string($envelope['signature']??null) ||
           !isset($this->trustedPublicKeys[$envelope['key_id']])) {
            throw new RegistrationError('untrusted_status_signer');
        }
        $bytes=self::decode($envelope['payload'],null);
        $sig=self::decode($envelope['signature'],64);
        $key=self::decode($this->trustedPublicKeys[$envelope['key_id']],32);
        // Exact-byte equality binds signer to this authenticated request
        // and prevents a callback substituting foreign or stale claims.
        if(!hash_equals($raw,$bytes) ||
           !sodium_crypto_sign_verify_detached(
              $sig,'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".$bytes,$key)) {
            throw new RegistrationError('invalid_signed_status_receipt');
        }
        $status['signed_receipt']=$envelope;
        return $status;
    }

    private static function decode(mixed $value,?int $size): string
    {
        if(!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+$/D',$value)){
            throw new RegistrationError('invalid_signed_status_encoding');
        }
        $raw=base64_decode(strtr($value,'-_','+/'),true);
        if($raw===false || ($size!==null&&strlen($raw)!==$size) ||
           rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$value){
            throw new RegistrationError('invalid_signed_status_encoding');
        }
        return $raw;
    }
}
