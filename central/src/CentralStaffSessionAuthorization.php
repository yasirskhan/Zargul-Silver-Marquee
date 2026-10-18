<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/**
 * Vendor-only capability gate for a server-authenticated staff bearer.
 * Never accept an HTTP-supplied "MFA verified" flag or actor staff UUID.
 * Customer scope is still enforced by the live grant reader on every call.
 * HTTP request Origin + CSRF must be separately checked for mutations.
 */
final class CentralStaffSessionAuthorization
{
    public function __construct(
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffAccess $access
    ) {}

    public function permits(string $sessionBearer,string $capability,
        ?string $customerId): bool
    {
        $actor=$this->sessions->staffFor($sessionBearer);
        if($actor===null)return false;
        return $this->access->permits($actor,$capability,$customerId,true);
    }
}
