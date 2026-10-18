<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * INTERNAL two-factor central staff credential check.
 *
 * Accepts untrusted credentials but NEVER accepts a caller-supplied
 * "password passed" assertion. Validates real vendor password against
 * isolated staff DB before using the protected MFA secret and replay ledger.
 * Returns staff ID only, NOT a session, cookie, role grant or API token.
 * This must remain inaccessible to HTTP until protected rate-limited vendor
 * auth, HTTPS, cookie/CSRF, KMS and security audit controls are implemented.
 */
final class CentralStaffCredentialVerifier
{
    public function __construct(
        private readonly CentralStaffPasswordVerifier $password,
        private readonly CentralStaffMfaChallenge $mfa
    ) {}

    public function verify(string $email,string $password,string $totp,int $serverUnixTime):?string
    {
        $staffId=$this->password->verify($email,$password);
        if($staffId===null)return null;
        return $this->mfa->verify($staffId,$totp,true,$serverUnixTime)
            ? $staffId : null;
    }
}
