<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL HAFZATECH auditor-only read of enrollment challenges and externally
 * signed-evidence check receipts. No HTTP route, no raw challenge/nonces,
 * signatures, hashes, credential material, PII or activation authority.
 */
final class CentralStaffEnrollmentAuditReader
{
    private const UUID='/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][0-9a-f]{3}-[a-f0-9]{12}$/D';

    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $auth
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listForTarget(string $auditorBearer,string $targetStaffId,
        int $limit=25):array
    {
        if(preg_match(self::UUID,$targetStaffId)!==1 ||
           $limit<1 || $limit>25) {
            throw new RegistrationError('invalid_staff_enrollment_audit_request',422);
        }
        if(!$this->auth->permits($auditorBearer,'audit.read',null)) {
            throw new RegistrationError('staff_enrollment_audit_denied');
        }
        $q=$this->db->prepare(
            'SELECT c.id AS challenge_id,c.target_staff_id,
                    c.issued_by_staff_id,c.provider_key_id,
                    c.issued_at,c.expires_at,
                    e.reviewed_by_staff_id,e.decision AS evidence_check,
                    e.recorded_at AS evidence_checked_at
             FROM central_staff_enrollment_challenges c
             LEFT JOIN central_staff_enrollment_evidence_checks e
               ON e.challenge_id=c.id
             WHERE c.target_staff_id=:target
             ORDER BY c.issued_at DESC,c.id DESC LIMIT :limit'
        );
        $q->bindValue(':target',$targetStaffId,PDO::PARAM_STR);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
