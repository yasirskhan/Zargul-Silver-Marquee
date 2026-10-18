<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL, separately default-disabled central vendor support reply bridge.
 * Staff identity/MFA is resolved ONLY from a live central session, not posted
 * staff IDs or 'MFA passed' flags. Ticket ownership is checked independently
 * before and inside the existing immutable/encrypted reply service.
 * The encrypted-content key belongs to an isolated operator secret provider.
 */
final class CentralStaffSessionSupportReply
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization,
        private readonly SupportAgentReplyService $replies,
        private readonly bool $enabled=false
    ) {}

    /** @return array{ticket_id:string,message_id:string,status:string} */
    public function reply(string $bearer,string $customerId,string $ticketId,
        string $messageId,string $body):array
    {
        if(!$this->enabled ||
            getenv('CENTRAL_STAFF_SUPPORT_REPLY_HTTP_ENABLED')!=='true') {
            throw new RegistrationError('staff_support_reply_not_enabled',503);
        }
        foreach([$customerId,$ticketId,$messageId] as $id) {
            if(!self::uuid($id)) {
                throw new RegistrationError('invalid_support_reply_request',422);
            }
        }
        if(strlen($body)>16000 || mb_strlen($body)<3 ||
            mb_strlen($body)>4000) {
            throw new RegistrationError('invalid_support_reply_request',422);
        }
        $actor=$this->sessions->staffFor($bearer);
        if($actor===null ||
            !$this->authorization->permits(
                $bearer,'support.reply',$customerId)) {
            throw new RegistrationError('support_scope_denied');
        }
        $q=$this->db->prepare(
            "SELECT t.customer_id FROM vendor_support_tickets t
             JOIN vendor_customers c
               ON c.id=t.customer_id AND c.status='active'
             WHERE t.id=:ticket AND t.customer_id=:customer"
        );
        $q->execute(['ticket'=>$ticketId,'customer'=>$customerId]);
        if($q->fetchColumn()!==$customerId) {
            throw new RegistrationError('support_scope_denied');
        }
        // Underlying service re-locks ticket/customer and independently
        // checks current staff roles in the immutable audit transaction.
        return $this->replies->reply(
            $ticketId,$messageId,$actor,$body,true);
    }

    private static function uuid(string $id):bool
    {
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $id)===1;
    }

    /** Only a safe confirmation status, never the confidential reply body. */
    public static function html(array $result):string
    {
        $status=(string)($result['status']??'');
        if(!in_array($status,['new','pending','in_progress',
            'waiting_for_client','on_hold','resolved','closed','reopened'],true)) {
            throw new RegistrationError('invalid_support_reply_result');
        }
        return '<section aria-label="Private HAFZATECH support reply receipt">'.
            '<h2>Support reply recorded</h2>'.
            '<p>Encrypted response saved with immutable audit. '.
            'Current ticket status: '.
            htmlspecialchars($status,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').
            '</p></section>';
    }
}
