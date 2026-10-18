<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * INTERNAL, independently disabled staff self-password route service.
 * Works ONLY on currently authenticated staff identity through the central
 * password service. The public caller can never select a target staff ID,
 * pretend MFA, reissue a revoked session, or retain any old session.
 */
final class CentralStaffPortalOwnPassword
{
    public function __construct(
        private readonly CentralStaffOwnPasswordChange $changes,
        private readonly bool $enabled=false
    ) {}

    public function change(string $bearer,string $oldPassword,
        string $newPassword):void
    {
        if(!$this->enabled ||
            getenv('CENTRAL_STAFF_PASSWORD_HTTP_ENABLED')!=='true') {
            throw new RegistrationError('staff_password_http_not_enabled',503);
        }
        if(strlen($oldPassword)>1024 || strlen($newPassword)<12 ||
            strlen($newPassword)>1024 ||
            !$this->changes->change($bearer,$oldPassword,$newPassword)) {
            // Never reveal which credential/requirement failed to remote user.
            throw new RegistrationError('staff_password_change_denied');
        }
        // A successful change atomically invalidates ALL the staff member's
        // sessions and writes immutable audit events inside the core service.
    }
}
