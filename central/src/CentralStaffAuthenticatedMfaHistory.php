<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/**
 * Internal vendor-only MFA journal adapter. Actor comes from the trusted
 * server-side staff session, never a caller supplied identity or MFA boolean.
 * This is not a public staff route.
 */
final class CentralStaffAuthenticatedMfaHistory
{
    public function __construct(
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffMfaAuditReader $audit
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function listForStaff(string $bearer,string $subjectStaffId,
        int $beforeId=0,int $limit=25):array
    {
        $actor=$this->sessions->staffFor($bearer);
        if($actor===null)
            throw new \RuntimeException('Verified HAFZATECH staff session required.');
        return $this->audit->listForStaff(
            $actor,$subjectStaffId,true,$beforeId,$limit
        );
    }
}
