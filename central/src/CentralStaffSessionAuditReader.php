<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use RuntimeException;

/**
 * Vendor-only immutable session-event reader. Actor comes from a live vendor
 * staff session, never an HTTP-supplied UUID or MFA assertion. The event
 * journal never includes passwords, OTPs, bearer tokens, IPs or CSRF proofs.
 *
 * This is an internal service only; no public staff endpoint is enabled.
 */
final class CentralStaffSessionAuditReader
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listForStaff(string $sessionBearer,string $subjectStaffId,
        int $beforeId=0,int $limit=25): array
    {
        if(!$this->authorization->permits($sessionBearer,'audit.read',null)){
            throw new RuntimeException('Protected central staff audit permission required.');
        }
        if($beforeId<0 || $limit<1 || $limit>100 ||
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $subjectStaffId)) {
            throw new RuntimeException('Invalid central staff session audit request.');
        }
        $q=$this->db->prepare(
            'SELECT id,staff_id,session_id,outcome,created_at
             FROM central_staff_session_events
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
