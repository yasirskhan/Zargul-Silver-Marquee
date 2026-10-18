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

    /** Exact JSON media type; optional UTF-8 charset is allowed, prefix matches are not. */
    public static function acceptsJson(?string $contentType): bool
    {
        if ($contentType === null) return false;
        return preg_match('/^application\\/json(?:\\s*;\\s*charset\\s*=\\s*(?:utf-8|"utf-8"))?\\s*$/iD', trim($contentType)) === 1;
    }

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
