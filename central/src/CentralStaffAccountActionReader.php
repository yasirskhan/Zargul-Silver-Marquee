<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use RuntimeException;

/**
 * Internal immutable HAFZATECH staff-account decision journal reader.
 * Actor is an authenticated vendor MFA session, never an HTTP-provided
 * actor UUID or Boolean proof. No public staff API route is registered.
 */
final class CentralStaffAccountActionReader
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $auth
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listForTarget(string $staffBearer,string $targetStaffId,
        int $beforeId=0,int $limit=25):array
    {
        if(!$this->auth->permits($staffBearer,'audit.read',null))
            throw new RuntimeException('Current vendor audit access required.');
        if($beforeId<0 || $limit<1 || $limit>100 ||
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $targetStaffId))
            throw new RuntimeException('Invalid staff account history request.');
        $q=$this->db->prepare(
            'SELECT id,actor_staff_id,target_staff_id,action,previous_status,
                    reason,occurred_at
             FROM central_staff_account_actions
             WHERE target_staff_id=:target AND (:before=0 OR id<:cursor)
             ORDER BY id DESC LIMIT :limit'
        );
        $q->bindValue(':target',$targetStaffId,PDO::PARAM_STR);
        $q->bindValue(':before',$beforeId,PDO::PARAM_INT);
        $q->bindValue(':cursor',$beforeId,PDO::PARAM_INT);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
