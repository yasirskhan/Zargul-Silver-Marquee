<?php
declare(strict_types=1);

namespace Hafzatech\Signing;

use InvalidArgumentException;

/** Protected-worker-only signing of company-scoped support reply pages.
 * Not loaded by public central routes. Only authorized isolated signing
 * worker may supply the key after authenticated pull authorization.
 */
final class Ed25519SupportReplyPageCodec
{
    public static function sign(array $page, string $customerId, string $installationId,
        string $nonce, int $requestedCursor, string $keyId, string $privateKey): array
    {
        if (strlen($privateKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ||
            preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$keyId)!==1 ||
            $requestedCursor<0 ||
            preg_match('/^[A-Za-z0-9_-]{43}$/D',$nonce)!==1) {
            throw new InvalidArgumentException('Invalid protected support signing inputs.');
        }
        foreach ([$customerId,$installationId] as $uuid) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$uuid)!==1) {
                throw new InvalidArgumentException('Invalid support scope.');
            }
        }
        if (($page['api_version']??null)!=='v1' ||
            !is_int($page['next_cursor']??null) ||
            $page['next_cursor']<$requestedCursor ||
            !is_array($page['messages']??null) ||
            count($page['messages'])>50) {
            throw new InvalidArgumentException('Invalid support page.');
        }
        $last=$requestedCursor;
        foreach($page['messages'] as $message){
            if (!is_array($message)||!is_int($message['event_id']??null)||
                $message['event_id']<=$last ||
                !is_string($message['body']??null) ||
                mb_strlen($message['body'])<1||mb_strlen($message['body'])>4000) {
                throw new InvalidArgumentException('Unordered or invalid support message.');
            }
            $last=$message['event_id'];
        }
        if ($last!==$page['next_cursor'])
            throw new InvalidArgumentException('Support cursor does not match page.');
        $body=[
            'api_version'=>'v1','issuer'=>'HAFZATECH',
            'customer_id'=>$customerId,'installation_id'=>$installationId,
            'nonce'=>$nonce,'requested_cursor'=>$requestedCursor,
            'next_cursor'=>$page['next_cursor'],'messages'=>$page['messages']
        ];
        $json=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if(strlen($json)>250000)throw new InvalidArgumentException('Support reply page too large.');
        $signature=sodium_crypto_sign_detached('HAFZATECH-SUPPORT-REPLY-PAGE-V1'."\n".$json,$privateKey);
        $encode=static fn(string $s):string=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
        return ['key_id'=>$keyId,'payload'=>$encode($json),'signature'=>$encode($signature)];
    }
}
