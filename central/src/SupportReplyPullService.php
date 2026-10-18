<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;
use PDO;

/**
 * Read-only encrypted-at-rest vendor agent reply stream. No public route.
 * Requires a fresh one-time Ed25519 installation check AND a separate
 * content-bound cursor signature. NEVER requires an unexpired paid license:
 * customers can contact support after their venue site is in maintenance.
 */
final class SupportReplyPullService
{
    /** @param Closure(string):string $decrypt */
    public function __construct(
        private readonly PDO $db,
        private readonly InstallationStatusService $status,
        private readonly Closure $decrypt
    ) {}

    /** @param array<string,mixed> $request
     * @return array{api_version:string,next_cursor:int,messages:array<int,array<string,mixed>>}
     */
    public function pull(array $request): array
    {
        if(getenv('CENTRAL_SUPPORT_PULL_ENABLED')!=='true')
            throw new RegistrationError('support_pull_not_enabled',503);
        foreach(['installation_id','customer_id','nonce','cursor_signature'] as $field) {
            if(!is_string($request[$field]??null))
                throw new RegistrationError('invalid_support_cursor_request',422);
        }
        $cursor=$request['cursor']??null;
        $limit=$request['limit']??null;
        if(!is_int($cursor)||$cursor<0||!is_int($limit)||$limit<1||$limit>50)
            throw new RegistrationError('invalid_support_cursor_request',422);
        $q=$this->db->prepare(
            "SELECT i.ed25519_public_key FROM registered_installations i
             JOIN vendor_customers c ON c.id=i.customer_id AND c.status='active'
             WHERE i.id=:installation AND i.customer_id=:customer AND i.status='active'"
        );
        $q->execute(['installation'=>$request['installation_id'],
            'customer'=>$request['customer_id']]);
        $pub=$q->fetchColumn();
        if(!is_string($pub))throw new RegistrationError('unauthorized_support_installation',403);
        $message=implode("\n",[
            'HAFZATECH-SUPPORT-CHANGES-V1',
            $request['installation_id'],$request['customer_id'],
            (string)$cursor,(string)$limit,$request['nonce'],
        ]);
        if(!sodium_crypto_sign_verify_detached(
            self::decode($request['cursor_signature'],64),$message,self::decode($pub,32)
        ))throw new RegistrationError('invalid_support_cursor_proof',403);

        $this->status->check($request);
        $q=$this->db->prepare(
            "SELECT audit.id AS event_id, messages.source_message_id AS message_id,
                    tickets.ticket_number,tickets.client_ticket_id,
                    encode(messages.body_ciphertext,'hex') AS ciphertext,
                    audit.occurred_at
             FROM vendor_support_reply_audit audit
             JOIN vendor_support_tickets tickets ON tickets.id=audit.ticket_id
                 AND tickets.customer_id=audit.customer_id
             JOIN vendor_support_messages messages ON messages.id=audit.message_id
                 AND messages.ticket_id=tickets.id
                 AND messages.customer_id=tickets.customer_id
                 AND messages.direction='staff'
             WHERE audit.id>:cursor AND tickets.customer_id=:customer
                 AND tickets.installation_id=:installation
             ORDER BY audit.id ASC LIMIT :limit"
        );
        $q->bindValue(':cursor',$cursor,PDO::PARAM_INT);
        $q->bindValue(':customer',$request['customer_id']);
        $q->bindValue(':installation',$request['installation_id']);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->execute();
        $messages=[];$nextCursor=$cursor;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $entry){
            $bytes=hex2bin($entry['ciphertext']);
            if($bytes===false)throw new RegistrationError('support_reply_unavailable');
            $body=($this->decrypt)($bytes);
            if(!is_string($body)||mb_strlen($body)<1||mb_strlen($body)>4000)
                throw new RegistrationError('support_reply_unavailable');
            $event=(int)$entry['event_id'];
            $messages[]=[
                'event_id'=>$event,
                'message_id'=>$entry['message_id'],
                'client_ticket_id'=>$entry['client_ticket_id'],
                'ticket_number'=>'HAF-'.str_pad((string)$entry['ticket_number'],8,'0',STR_PAD_LEFT),
                'sender'=>'HAFZATECH Support',
                'body'=>$body,
                'occurred_at'=>(new \DateTimeImmutable($entry['occurred_at']))
                    ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ];
            $nextCursor=$event;
        }
        return ['api_version'=>'v1','next_cursor'=>$nextCursor,'messages'=>$messages];
    }

    private static function decode(string $text,int $len): string
    {
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$text))
            throw new RegistrationError('invalid_support_cursor_encoding',422);
        $binary=base64_decode(strtr($text,'-_','+/'),true);
        if($binary===false||strlen($binary)!==$len||
           rtrim(strtr(base64_encode($binary),'+/','-_'),'=')!==$text)
            throw new RegistrationError('invalid_support_cursor_encoding',422);
        return $binary;
    }
}
