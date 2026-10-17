<?php

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Read-only decision engine for a grant already authenticated by
 * CentralSignedGrantVerifier. NOT yet connected to live public/site gates.
 * Missed central checks alone never revoke a locally valid signed license.
 */
final class CentralLicenseStatusPolicy
{
    /** Fifteen calendar days since last successful ONLINE check-in, no 5m polling. */
    public function nextCentralVerification(
        ?DateTimeImmutable $lastSuccessfulOnlineCheck, DateTimeImmutable $now
    ): DateTimeImmutable {
        $utc = new DateTimeZone('UTC');
        if ($lastSuccessfulOnlineCheck === null) {
            return $now->setTimezone($utc);
        }
        return $lastSuccessfulOnlineCheck->setTimezone($utc)->add(new DateInterval('P15D'));
    }

    /**
     * @param array{grant:array<string,mixed>,active:bool} $verified
     * @return array{public:string,dashboard:string,expiry_warning:bool,expires_at:string}
     */
    public function state(array $verified, DateTimeImmutable $now): array
    {
        $expires = $verified['grant']['expires_at'] ?? null;
        $starts = $verified['grant']['not_before'] ?? null;
        if (! is_string($expires) || ! is_string($starts) ||
            ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $expires) ||
            ! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $starts)) {
            throw new InvalidArgumentException('A signed and verified UTC grant is required.');
        }
        $utc = new DateTimeZone('UTC');
        $expiry = new DateTimeImmutable($expires, $utc);
        $start = new DateTimeImmutable($starts, $utc);
        $nowUtc = $now->setTimezone($utc);
        if ($start >= $expiry) {
            throw new InvalidArgumentException('Signed license period invalid.');
        }
        $warningStart = $expiry->sub(new DateInterval('P15D'));
        $warning = $nowUtc >= $warningStart && $nowUtc < $expiry;
        if ($nowUtc < $start) {
            return ['public'=>'maintenance', 'dashboard'=>'restricted',
                'expiry_warning'=>false, 'expires_at'=>$expires];
        }
        if ($nowUtc < $expiry) {
            if ($verified['active'] !== true) {
                throw new InvalidArgumentException('Verification and signed times disagree.');
            }
            return ['public'=>'open','dashboard'=>'operational',
                'expiry_warning'=>$warning,'expires_at'=>$expires];
        }
        $graceEnd=$expiry->add(new DateInterval('P7D'));
        return ['public'=>'maintenance',
            'dashboard'=>$nowUtc < $graceEnd ? 'grace' : 'restricted',
            'expiry_warning'=>false,'expires_at'=>$expires];
    }
}
