<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;
use PDO;
use Throwable;

/**
 * INTERNAL trusted HAFZATECH staff action. No public staff login or customer
 * route uses this yet. Future caller must derive staffId and MFA session
 * assertion from vendor-owned auth middleware, NEVER HTTP JSON.
 */
final class SupportAgentReplyService
{
    /** @param Closure(string):string $encrypt */
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffAccess $access,
        private readonly Closure $encrypt
    ) {}

    /** @return array{ticket_id:string,message_id:string,status:string} */
    public function reply(string $ticketId,string $messageId,string $staffId,
        string $body,bool $trustedMfaSession): array
    {
        if(getenv('CENTRAL_SUPPORT_STAFF_REPLY_ENABLED')!=='true')
            throw new RegistrationError('support_reply_disabled',503);
        foreach([$ticketId,$messageId,$staffId] as $id){
            if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$id))
                throw new RegistrationError('invalid_support_identifier',422);
        }
        if(!$trustedMfaSession||mb_strlen($body)<3||mb_strlen($body)>4000)
            throw new RegistrationError('unauthorized_support_reply',403);
        $digest=hash('sha256',$body);
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare(
                "SELECT id,customer_id,status FROM vendor_support_tickets
                 WHERE id=:ticket FOR UPDATE"
            );
            $stmt->execute(['ticket'=>$ticketId]);
            $ticket=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$ticket || !$this->access->permits($staffId,'support.reply',
                $ticket['customer_id'],true)){
                throw new RegistrationError('support_scope_denied',403);
            }
            $existing=$this->db->prepare(
                "SELECT id,author_staff_id,content_sha256 FROM vendor_support_messages
                 WHERE ticket_id=:ticket AND source_message_id=:source FOR UPDATE"
            );
            $existing->execute(['ticket'=>$ticketId,'source'=>$messageId]);
            $found=$existing->fetch(PDO::FETCH_ASSOC);
            if($found){
                if($found['author_staff_id']!==$staffId ||
                   !hash_equals((string)($found['content_sha256']??''),$digest)){
                    throw new RegistrationError('support_reply_idempotency_conflict',409);
                }
                $this->db->commit();
                return ['ticket_id'=>$ticketId,'message_id'=>$messageId,
                    'status'=>$ticket['status']];
            }
            if($ticket['status']==='closed')
                throw new RegistrationError('support_ticket_closed',409);
            $sealed=($this->encrypt)($body);
            if(!is_string($sealed)||strlen($sealed)<16)
                throw new RegistrationError('support_encrypt_unavailable',503);
            $stmt=$this->db->prepare(
                "INSERT INTO vendor_support_messages
                 (id,ticket_id,customer_id,source_message_id,direction,
                  author_staff_id,body_ciphertext,content_sha256)
                 VALUES(:id,:ticket,:customer,:source,'staff',:staff,
                 decode(:cipher,'hex'),:digest)"
            );
            $newId=self::uuid();
            $stmt->execute(['id'=>$newId,'ticket'=>$ticketId,
                'customer'=>$ticket['customer_id'],'source'=>$messageId,
                'staff'=>$staffId,'cipher'=>bin2hex($sealed),'digest'=>$digest]);
            $stmt=$this->db->prepare(
                "INSERT INTO vendor_support_reply_audit
                 (ticket_id,customer_id,staff_id,message_id,action)
                 VALUES(:ticket,:customer,:staff,:message,'support.agent_reply')"
            );
            $stmt->execute(['ticket'=>$ticketId,'customer'=>$ticket['customer_id'],
                'staff'=>$staffId,'message'=>$newId]);
            $this->db->prepare(
                "UPDATE vendor_support_tickets SET status='waiting_for_client' WHERE id=:ticket"
            )->execute(['ticket'=>$ticketId]);
            $this->db->commit();
            return ['ticket_id'=>$ticketId,'message_id'=>$messageId,
                'status'=>'waiting_for_client'];
        } catch(Throwable $error) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
    }

    private static function uuid(): string {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
