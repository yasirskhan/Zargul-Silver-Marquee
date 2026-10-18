<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/**
 * Server-side policy helper only. Do NOT expose central staff HTTP endpoints
 * before independent KMS secrets, proxy hardening, CSRF-bound sessions,
 * operators, audit and production release approvals are ready.
 */
final class CentralStaffHttpPolicy
{
    public const ORIGIN = 'https://lichall.hafzatech.com';
    public const COOKIE_NAME = '__Host-HafzatechStaff';

    /** Validates trusted TLS server variables; never trusts forwarded headers. */
    public static function secureHost(array $server): bool
    {
        $tls=isset($server['HTTPS']) &&
            in_array(strtolower((string)$server['HTTPS']),['on','1'],true);
        $tls=$tls || (string)($server['SERVER_PORT']??'')==='443';
        return $tls && ($server['HTTP_HOST']??null)==='lichall.hafzatech.com';
    }

    /** Fail-closed protection for every future state-changing /staff request. */
    public static function staffMutationAllowed(array $server): bool
    {
        if(!self::secureHost($server))return false;
        $method=strtoupper((string)($server['REQUEST_METHOD']??''));
        if(!in_array($method,['POST','PUT','PATCH','DELETE'],true))
            return false;
        $path=parse_url((string)($server['REQUEST_URI']??'/'),PHP_URL_PATH);
        if(!is_string($path) ||
           !($path==='/staff' || str_starts_with($path,'/staff/')))
            return false;
        if(($server['HTTP_ORIGIN']??null)!==self::ORIGIN)return false;
        $fetch=$server['HTTP_SEC_FETCH_SITE']??null;
        return $fetch===null || $fetch==='same-origin';
    }

    /** A fixed secure host-only cookie, never a session ID in a query string. */
    public static function cookieOptions(int $issuedAt): array
    {
        if($issuedAt<1)throw new \InvalidArgumentException('Trusted time required');
        return [
            'expires'=>$issuedAt+43200,
            'path'=>'/',
            'secure'=>true,
            'httponly'=>true,
            'samesite'=>'Strict'
        ];
    }

    /** Peer transport address only. Do not trust arbitrary X-Forwarded-For. */
    public static function directPeerIp(array $server): ?string
    {
        $ip=$server['REMOTE_ADDR']??null;
        return is_string($ip) && filter_var($ip,FILTER_VALIDATE_IP)!==false
            ?$ip:null;
    }
}
