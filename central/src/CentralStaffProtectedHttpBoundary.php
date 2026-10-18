<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/**
 * Internal composition of vendor staff HTTP security prerequisites, NOT a
 * routed login controller. This stays default-OFF and cannot authorize HTTP
 * without a protected operator construction path, trusted TLS/proxy, KMS,
 * audit and deployment readiness signoff.
 *
 * Caller MUST send bearer only as Secure HttpOnly host-only cookie and CSRF
 * only in a same-origin page/header. Never put secrets in URLs or logs.
 */
final class CentralStaffProtectedHttpBoundary
{
    public function __construct(
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffCsrfTokenService $csrf,
        private readonly CentralStaffSessionAuthorization $authorization,
        private readonly bool $enabled = false
    ) {}

    /**
     * @return array{cookie_name:string,cookie_value:string,cookie_options:array,csrf:string}|null
     * Secrets are INTERNAL response material, never public JSON.
     */
    public function authenticate(array $server,string $email,string $password,string $totp): ?array
    {
        $path=parse_url((string)($server['REQUEST_URI']??'/'),PHP_URL_PATH);
        if(!$this->enabled || $path!=='/staff/session/login' ||
           !CentralStaffHttpPolicy::staffMutationAllowed($server))return null;
        $ip=CentralStaffHttpPolicy::directPeerIp($server);
        if($ip===null)return null;
        $bearer=$this->sessions->login($email,$password,$totp,$ip);
        if($bearer===null)return null;
        $csrf=$this->csrf->issue($bearer);
        if($csrf===null){
            $this->sessions->logout($bearer);
            return null;
        }
        return [
            'cookie_name'=>CentralStaffHttpPolicy::COOKIE_NAME,
            'cookie_value'=>$bearer,
            'cookie_options'=>CentralStaffHttpPolicy::cookieOptions(time()),
            'csrf'=>$csrf
        ];
    }

    public function permitsMutation(array $server,string $sessionBearer,
        string $submittedCsrf,string $capability,?string $customerId): bool
    {
        return $this->enabled &&
            CentralStaffHttpPolicy::staffMutationAllowed($server) &&
            $this->csrf->accepts($sessionBearer,$submittedCsrf) &&
            $this->authorization->permits($sessionBearer,$capability,$customerId);
    }

    /** Same-origin, CSRF-bound CURRENT staff session for self-only actions.
     * No arbitrary business capability is implied or granted by this check.
     */
    public function permitsOwnAccountMutation(array $server,string $bearer,
        string $submittedCsrf):bool
    {
        return $this->enabled &&
            CentralStaffHttpPolicy::staffMutationAllowed($server) &&
            $this->csrf->accepts($bearer,$submittedCsrf) &&
            $this->sessions->staffFor($bearer)!==null;
    }

    public function signOut(array $server,string $sessionBearer,string $submittedCsrf): bool
    {
        $path=parse_url((string)($server['REQUEST_URI']??'/'),PHP_URL_PATH);
        return $this->enabled && $path==='/staff/session/logout' &&
            CentralStaffHttpPolicy::staffMutationAllowed($server) &&
            $this->csrf->accepts($sessionBearer,$submittedCsrf) &&
            $this->sessions->logout($sessionBearer);
    }
}
