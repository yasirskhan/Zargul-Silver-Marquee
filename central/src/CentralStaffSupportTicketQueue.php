<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Internal metadata-only support inbox, protected by a live central vendor
 * MFA session with customer-scoped support.ticket.read. Encrypted subjects,
 * customer messages and private keys are not loaded or returned.
 */
final class CentralStaffSupportTicketQueue
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listForCustomer(string $bearer,string $customerId,
        int $offset=0,int $limit=25): array
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $customerId)!==1 || $offset<0 || $offset>10000 ||
            $limit<1 || $limit>25) {
            throw new RegistrationError('invalid_support_queue_request',422);
        }
        if (!$this->authorization->permits($bearer,'support.ticket.read',$customerId)) {
            throw new RegistrationError('support_scope_denied');
        }
        $q=$this->db->prepare(
            "SELECT t.ticket_number,t.status,t.created_at
             FROM vendor_support_tickets t
             JOIN vendor_customers c
               ON c.id=t.customer_id AND c.status='active'
             WHERE t.customer_id=:customer
             ORDER BY t.created_at DESC,t.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $q->bindValue(':customer',$customerId,PDO::PARAM_STR);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->bindValue(':offset',$offset,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
