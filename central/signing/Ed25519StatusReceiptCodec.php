<?php
declare(strict_types=1);

namespace Hafzatech\Signing;

use InvalidArgumentException;

/**
 * Isolated signer primitive for installation status receipts.
 * Never register this class in central/public, customer routes or ordinary
 * staff controllers. A protected signing worker must authorize every
 * invocation and keep its key in a KMS/HSM-equivalent boundary.
 * This is a cryptographic primitive, NOT authorization or entitlement.
 */
final class Ed25519StatusReceiptCodec
{
    /** @param array<string,mixed> $payload
     * @return array{key_id:string,payload:string,signature:string}
     */
    public static function sign(array $payload, string $keyId, string $privateKey): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$keyId) ||
            strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('Invalid protected signing credentials.');
        }
        $keys=array_keys($payload);
        sort($keys);
        $expected=['api_version','issuer','installation_id','customer_id',
            'nonce','status','revision','issued_at'];
        sort($expected);
        if ($keys!==$expected || ($payload['api_version']??null)!=='v1' ||
            ($payload['issuer']??null)!=='HAFZATECH' ||
            !in_array($payload['status']??null,[
                'registered_pending_license','signed_revision_pending_delivery',
                'suspended','revoked'],true) ||
            !is_int($payload['revision']??null) ||
            $payload['revision']<0 || $payload['revision']>2147483647 ||
            (($payload['status']==='registered_pending_license')!==($payload['revision']===0))) {
            throw new InvalidArgumentException('Unexpected signed status semantics.');
        }
        foreach(['installation_id','customer_id'] as $key) {
            if(!is_string($payload[$key]) ||
                !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$payload[$key])) {
                throw new InvalidArgumentException('Invalid status identity UUID.');
            }
        }
        if (!is_string($payload['nonce']) ||
            !preg_match('/^[A-Za-z0-9_-]{43}$/D',$payload['nonce']) ||
            strlen((string)base64_decode(strtr($payload['nonce'],'-_','+/'),true))!==32 ||
            !is_string($payload['issued_at']) ||
            !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$payload['issued_at'])) {
            throw new InvalidArgumentException('Invalid nonce or UTC issued time.');
        }
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>4096)throw new InvalidArgumentException('Oversized status receipt.');
        $signature=sodium_crypto_sign_detached(
            'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'."\n".$json,$privateKey
        );
        return [
            'key_id'=>$keyId,
            'payload'=>self::b64($json),
            'signature'=>self::b64($signature)
        ];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
    }
}
