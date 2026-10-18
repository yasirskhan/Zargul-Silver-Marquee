<?php
declare(strict_types=1);
namespace Hafzatech\Central;

use PDO;
use RuntimeException;

/**
 * Private credential-change security history. Staff identity and audit.read
 * must come from a current vendor MFA session, not browser-supplied actor data.
 * Does not expose password hashes, session tokens or customer information.
 */
final class CentralStaffPasswordChangeAuditReader
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    public function listForStaff(string $bearer,string $subjectId,
        int $beforeId=0,int $limit=25):array
    {
        if(!$this->authorization->permits($bearer,'audit.read',null))
            throw new RuntimeException('Vendor security auditor session required.');
        if($beforeId<0 || $limit<1 || $limit>100 ||
           !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$subjectId))
            throw new RuntimeException('Invalid credential history request.');
        $query=$this->db->prepare(
            'SELECT id,staff_id,outcome,occurred_at
             FROM central_staff_password_change_events
             WHERE staff_id=:staff AND (:before=0 OR id<:cursor)
             ORDER BY id DESC LIMIT :limit'
        );
        $query->bindValue(':staff',$subjectId,PDO::PARAM_STR);
        $query->bindValue(':before',$beforeId,PDO::PARAM_INT);
        $query->bindValue(':cursor',$beforeId,PDO::PARAM_INT);
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
