<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use RuntimeException;

/**
 * Vendor-only immutable MFA security history. Caller staff identity and MFA
 * assertion MUST come from a trusted protected central staff session, never
 * from query parameters or VenueDesk tenant sessions.
 */
final class CentralStaffMfaAuditReader
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffAccess $access
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listForStaff(string $actorId,string $subjectStaffId,
        bool $trustedSessionMfaVerified,int $beforeId=0,int $limit=25):array
    {
        if (!$this->access->permits($actorId,'audit.read',null,$trustedSessionMfaVerified)) {
            throw new RuntimeException('Protected vendor audit permission required.');
        }
        if ($beforeId<0 || $limit<1 || $limit>100 ||
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$subjectStaffId)) {
            throw new RuntimeException('Invalid MFA audit history request.');
        }
        $q=$this->db->prepare(
            'SELECT id,staff_id,outcome,accepted_counter,occurred_at
             FROM central_staff_mfa_attempt_audit
             WHERE staff_id=:subject AND (:before=0 OR id<:cursor)
             ORDER BY id DESC LIMIT :limit'
        );
        $q->bindValue(':subject',$subjectStaffId,PDO::PARAM_STR);
        $q->bindValue(':before',$beforeId,PDO::PARAM_INT);
        $q->bindValue(':cursor',$beforeId,PDO::PARAM_INT);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
