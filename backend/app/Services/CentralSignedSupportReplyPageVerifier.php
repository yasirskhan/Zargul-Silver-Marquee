<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Pure pinned-vendor signature verifier for support-reply pages.
 * No untrusted response may change a cursor or create inbox messages until
 * identity, nonce and page ordering pass these checks.
 */
final class CentralSignedSupportReplyPageVerifier
{
    public function verify(array $envelope, string $customerId, string $installationId,
        string $nonce, int $requestedCursor): array
    {
        if ($requestedCursor < 0 || count($envelope) !== 3 ||
            !is_string($envelope['key_id'] ?? null) ||
            !is_string($envelope['payload'] ?? null) ||
            !is_string($envelope['signature'] ?? null)) {
            throw new InvalidArgumentException('Invalid signed support envelope.');
        }
        $keys=config('central_control.trusted_signing_public_keys',[]);
        if (!is_array($keys) || !isset($keys[$envelope['key_id']]) ||
            !is_string($keys[$envelope['key_id']])) {
            throw new InvalidArgumentException('Unknown trusted vendor support signer.');
        }
        $public=self::decode($keys[$envelope['key_id']],32);
        $payload=self::decode($envelope['payload'],null);
        $signature=self::decode($envelope['signature'],64);
        if(strlen($payload)>250000 || !sodium_crypto_sign_verify_detached(
            $signature,'HAFZATECH-SUPPORT-REPLY-PAGE-V1'."\n".$payload,$public)) {
            throw new InvalidArgumentException('Invalid vendor support signature.');
        }
        $data=json_decode($payload,true,18,JSON_THROW_ON_ERROR);
        if (!is_array($data)||array_is_list($data) ||
            count($data)!==8 || ($data['issuer']??null)!=='HAFZATECH' ||
            ($data['api_version']??null)!=='v1' ||
            ($data['customer_id']??null)!==$customerId ||
            ($data['installation_id']??null)!==$installationId ||
            ($data['nonce']??null)!==$nonce ||
            ($data['requested_cursor']??null)!==$requestedCursor ||
            !is_int($data['next_cursor']??null) ||
            $data['next_cursor']<$requestedCursor ||
            !is_array($data['messages']??null) ||
            !array_is_list($data['messages']) ||
            count($data['messages'])>50) {
            throw new InvalidArgumentException('Support page identity or cursor mismatch.');
        }
        $last=$requestedCursor;
        foreach($data['messages'] as $message) {
            if (!is_array($message) ||
                !is_int($message['event_id']??null) ||
                $message['event_id']<=$last ||
                !is_string($message['body']??null) ||
                mb_strlen($message['body'])<1 ||
                mb_strlen($message['body'])>4000 ||
                !is_string($message['client_ticket_id']??null) ||
                !is_string($message['message_id']??null)) {
                throw new InvalidArgumentException('Invalid ordered vendor support reply.');
            }
            $last=$message['event_id'];
        }
        if($last!==$data['next_cursor']) {
            throw new InvalidArgumentException('Signed next cursor mismatches delivered messages.');
        }
        return ['next_cursor'=>$last,'messages'=>$data['messages']];
    }

    private static function decode(string $text,?int $bytes):string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D',$text))
            throw new InvalidArgumentException('Noncanonical vendor signature encoding.');
        $raw=base64_decode(strtr($text,'-_','+/'),true);
        if($raw===false || ($bytes!==null && strlen($raw)!==$bytes) ||
            rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$text)
            throw new InvalidArgumentException('Invalid vendor signature encoding.');
        return $raw;
    }
}
