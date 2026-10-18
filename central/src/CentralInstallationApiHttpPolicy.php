<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Exact vendor-domain release guard for installation endpoints. Host checks
 * cannot substitute for TLS/DNS/certificates, reverse-proxy validation,
 * installation Ed25519 proofs, replay protection or the default OFF gate.
 * Only the single owner-approved vendor domain can serve registration/status.
 */
final class CentralInstallationApiHttpPolicy
{
    public const HOST = 'lichall.hafzatech.com';

    public static function trustedHost(array $server): bool
    {
        if (($server['HTTP_HOST']??null)!==self::HOST) return false;

        // Never derive HTTPS from an untrusted forwarding header or a Host
        // containing attacker-supplied alternate port/credentials.
        $https=strtolower((string)($server['HTTPS']??''));
        if ($https==='off'||$https==='0') return false;
        return in_array($https,['on','1'],true)
            || ($https==='' && (string)($server['SERVER_PORT']??'')==='443');
    }
}
