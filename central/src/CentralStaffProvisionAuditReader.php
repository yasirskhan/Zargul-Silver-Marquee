<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL, MFA- and audit.read-protected view of immutable vendor employee
 * account provisioning decisions. Only allowlisted event metadata is read,
 * never provisional password hashes, MFA records or private request reason.
 */
final class CentralStaffProvisionAuditReader
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $auth
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listForTarget(string $bearer,string $targetStaffId,
        int $limit=25):array
    {
        if(preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $targetStaffId)!==1 || $limit<1 || $limit>25) {
            throw new RegistrationError('invalid_provision_audit_request',422);
        }
        if(!$this->auth->permits($bearer,'audit.read',null)) {
            throw new RegistrationError('staff_audit_denied');
        }
        $q=$this->db->prepare(
            'SELECT actor_staff_id,action,occurred_at
             FROM central_staff_provision_events
             WHERE target_staff_id=:target
             ORDER BY id DESC LIMIT :limit'
        );
        $q->bindValue(':target',$targetStaffId,PDO::PARAM_STR);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
