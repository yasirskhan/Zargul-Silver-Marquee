<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;
use PDO;
use Throwable;

/**
 * INTERNAL central receiver. A signed installation identity and separately
 * signed support payload are required. No public route, staff impersonation or
 * support encryption keys are provisioned by this batch.
 */
final class SupportTicketIngress
{
    /** @param Closure(string):string $encrypt */
    public function __construct(
        private readonly PDO $db,
        private readonly InstallationStatusService $status,
        private readonly Closure $encrypt
    ) {}

    /** @param array<string,mixed> $input
     * @return array{api_version:string,ticket_id:string,ticket_number:string,status:string}
     */
    public function receive(array $input): array
    {
        if (getenv('CENTRAL_SUPPORT_INGRESS_ENABLED') !== 'true') {
            throw new RegistrationError('support_ingress_not_enabled',503);
        }
        foreach(['installation_id','customer_id','client_ticket_id','nonce',
                 'subject','body','content_signature'] as $field){
            if(!is_string($input[$field]??null))throw new RegistrationError('invalid_support_request',422);
        }
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $input['client_ticket_id']) ||
            mb_strlen($input['subject'])<4 || mb_strlen($input['subject'])>160 ||
            mb_strlen($input['body'])<3 || mb_strlen($input['body'])>4000) {
            throw new RegistrationError('invalid_support_content',422);
        }
        $requestMessage=implode("\n",[
            'HAFZATECH-SUPPORT-CREATE-V1',
            $input['installation_id'],$input['customer_id'],
            $input['client_ticket_id'],$input['nonce'],
            hash('sha256',$input['subject']),hash('sha256',$input['body']),
        ]);
        $signature=self::decode($input['content_signature'],64);
        $key=$this->db->prepare(
            "SELECT i.ed25519_public_key FROM registered_installations i
             JOIN vendor_customers c ON c.id=i.customer_id AND c.status='active'
             WHERE i.id=:installation AND i.customer_id=:customer AND i.status='active'"
        );
        $key->execute([
            'installation'=>$input['installation_id'],'customer'=>$input['customer_id'],
        ]);
        $public=$key->fetchColumn();
        if(!is_string($public) || !sodium_crypto_sign_verify_detached(
            $signature,$requestMessage,self::decode($public,32)
        )){
            throw new RegistrationError('invalid_support_proof',403);
        }
        // Independently authenticate the request's canonical versioned signed
        // one-use status challenge and consume its nonce before storing content.
        $this->status->check($input);

        $digest=hash('sha256',json_encode([
            $input['subject'],$input['body'],
        ],JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try{
            $this->db->exec('SELECT pg_advisory_xact_lock(708116, 1118)');
            $q=$this->db->prepare(
                "SELECT id,ticket_number,content_sha256,status
                 FROM vendor_support_tickets
                 WHERE customer_id=:customer AND installation_id=:installation
                 AND client_ticket_id=:client FOR UPDATE"
            );
            $q->execute(['customer'=>$input['customer_id'],
                'installation'=>$input['installation_id'],
                'client'=>$input['client_ticket_id']]);
            $stored=$q->fetch(PDO::FETCH_ASSOC);
            if($stored){
                if(!hash_equals(trim($stored['content_sha256']),$digest))
                    throw new RegistrationError('support_idempotency_conflict',409);
                $this->db->commit();
                return self::result($stored,$input['customer_id'],$input['client_ticket_id']);
            }
            $subjectCipher=($this->encrypt)($input['subject']);
            $bodyCipher=($this->encrypt)($input['body']);
            if(!is_string($subjectCipher)||strlen($subjectCipher)<16||
               !is_string($bodyCipher)||strlen($bodyCipher)<16){
                throw new RegistrationError('support_encryption_unavailable',503);
            }
            $id=self::uuid();$messageId=self::uuid();
            $q=$this->db->prepare(
                "INSERT INTO vendor_support_tickets
                 (id,customer_id,installation_id,client_ticket_id,subject_ciphertext,content_sha256)
                 VALUES(:id,:customer,:installation,:client,decode(:cipher,'hex'),:digest)
                 RETURNING id,ticket_number,content_sha256,status"
            );
            $q->execute(['id'=>$id,'customer'=>$input['customer_id'],
                'installation'=>$input['installation_id'],'client'=>$input['client_ticket_id'],
                'cipher'=>bin2hex($subjectCipher),'digest'=>$digest]);
            $saved=$q->fetch(PDO::FETCH_ASSOC);
            $q=$this->db->prepare(
                "INSERT INTO vendor_support_messages
                 (id,ticket_id,customer_id,source_message_id,direction,body_ciphertext)
                 VALUES(:id,:ticket,:customer,:source,'customer',decode(:body,'hex'))"
            );
            $q->execute(['id'=>$messageId,'ticket'=>$id,'customer'=>$input['customer_id'],
                'source'=>$input['client_ticket_id'],'body'=>bin2hex($bodyCipher)]);
            $this->db->commit();
            return self::result($saved,$input['customer_id'],$input['client_ticket_id']);
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $row */
    private static function result(array $row,string $customerId,string $clientTicketId): array
    {
        return ['api_version'=>'v1','ticket_id'=>$row['id'],
            'client_ticket_id'=>$clientTicketId,'customer_id'=>$customerId,
            'ticket_number'=>'HAF-'.str_pad((string)$row['ticket_number'],8,'0',STR_PAD_LEFT),
            'status'=>$row['status']];
    }
    private static function decode(string $value,int $length): string
    {
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$value))
            throw new RegistrationError('invalid_support_encoding',422);
        $bytes=base64_decode(strtr($value,'-_','+/'),true);
        if($bytes===false||strlen($bytes)!==$length||
           rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')!==$value)
            throw new RegistrationError('invalid_support_encoding',422);
        return $bytes;
    }
    private static function uuid(): string
    {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
