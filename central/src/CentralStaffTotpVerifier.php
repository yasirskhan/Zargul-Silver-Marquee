<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Pure RFC 6238 SHA-1 TOTP verifier for the HAFZATECH staff trust boundary.
 * A PROTECTED central-auth layer must decrypt the per-staff secret, throttle
 * attempts, serialize row locks and atomically persist the returned counter.
 * Never accept MFA assertions directly from client-submitted JSON and never
 * use this primitive as an independent authorization grant.
 */
final class CentralStaffTotpVerifier
{
    /**
     * @return int|null The accepted monotonic 30-second counter; null on
     * invalid/replayed/expired code. The caller must persist it atomically.
     */
    public static function matchCounter(string $secret, string $code,
        int $unixTime, int $lastAcceptedCounter): ?int
    {
        if (strlen($secret)<16 || strlen($secret)>64 ||
            preg_match('/^[0-9]{6}$/D',$code)!==1 ||
            $unixTime<0 || $lastAcceptedCounter< -1) {
            return null;
        }
        $current=intdiv($unixTime,30);
        $start=max(0,$current-1);
        $match=null;
        for($counter=$start;$counter<=$current+1;$counter++){
            $high=intdiv($counter,4294967296);
            $low=$counter%4294967296;
            $bytes=pack('N2',$high,$low);
            $hmac=hash_hmac('sha1',$bytes,$secret,true);
            $offset=ord($hmac[19]) & 0x0f;
            $value=((ord($hmac[$offset])&0x7f)<<24)
                | (ord($hmac[$offset+1])<<16)
                | (ord($hmac[$offset+2])<<8)
                | ord($hmac[$offset+3]);
            $expected=str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
            if($counter>$lastAcceptedCounter && hash_equals($expected,$code)) {
                $match=$counter;
            }
        }
        return $match;
    }
}
