<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;

/**
 * Internal response emission boundary, NOT a public front controller.
 * A trusted operator process must inject native HTTP writers, with production
 * HTTPS/vhost/session vault checks outside this class. Default OFF.
 *
 * Validate EVERYTHING before sending headers, secure cookie and HTML body.
 * Never serialize plans as JSON, emit bearer in the body, or log response
 * arrays; the login plan contains a confidential HttpOnly cookie value.
 */
final class CentralStaffPortalResponseEmitter
{
    /**
     * @param Closure(string):void $writeHeader
     * @param Closure(string,string,array):bool $writeCookie
     * @param Closure(string):void $writeBody
     * @param Closure():bool $headersSent
     */
    public function __construct(
        private readonly Closure $writeHeader,
        private readonly Closure $writeCookie,
        private readonly Closure $writeBody,
        private readonly Closure $headersSent,
        private readonly bool $enabled=false
    ) {}

    /**
     * @param array<string,mixed> $response Internal private response ONLY
     */
    public function emit(array $response): void
    {
        if (!$this->enabled ||
            getenv('CENTRAL_STAFF_RESPONSE_EMITTER_ENABLED')!=='true') {
            throw new RegistrationError('staff_emitter_not_enabled',503);
        }
        $headers=$response['headers']??null;
        $cookies=$response['cookies']??null;
        $body=$response['body']??null;
        if (($response['status']??null)!==200 ||
            !is_array($headers) || !is_array($cookies) ||
            !is_string($body) || strlen($body)>262144 ||
            !str_starts_with($body,'<!doctype html>') &&
            !str_starts_with($body,'<section aria-label="Private HAFZATECH ') ||
            ($this->headersSent)()) {
            throw new RegistrationError('invalid_staff_emission');
        }
        // Never allow arbitrary injected response headers or a public/cachable
        // fallback even if a compromised internal callback supplies them.
        $expected=[
            'Content-Type'=>'text/html; charset=utf-8',
            'Cache-Control'=>'no-store, private',
            'X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer',
            'X-Frame-Options'=>'DENY',
            'Content-Security-Policy'=>
                "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
        ];
        if ($headers!==$expected || count($cookies)>1) {
            throw new RegistrationError('invalid_staff_emission');
        }
        $cookie=null;
        if (count($cookies)===1) {
            $cookie=$cookies[0]??null;
            $options=is_array($cookie)?($cookie['options']??null):null;
            if (!is_array($cookie) ||
                ($cookie['name']??null)!==CentralStaffHttpPolicy::COOKIE_NAME ||
                !is_string($cookie['value']??null) ||
                !is_array($options) ||
                array_keys($options)!==['expires','path','secure','httponly','samesite'] ||
                ($options['path']??null)!=='/' ||
                ($options['secure']??null)!==true ||
                ($options['httponly']??null)!==true ||
                ($options['samesite']??null)!=='Strict' ||
                !is_int($options['expires']??null) ||
                ($cookie['value']!=='' &&
                   preg_match('/^[a-f0-9]{64}$/D',$cookie['value'])!==1) ||
                ($cookie['value']==='' && $options['expires']!==1) ||
                ($cookie['value']!=='' &&
                   ($options['expires']<=time() ||
                    $options['expires']>time()+43260))) {
                throw new RegistrationError('invalid_staff_emission');
            }
            // The bearer may ONLY enter a host-only Secure HttpOnly cookie.
            if ($cookie['value']!=='' &&
                (str_contains($body,$cookie['value']) ||
                 str_contains(json_encode($headers,JSON_THROW_ON_ERROR),
                     $cookie['value']))) {
                throw new RegistrationError('invalid_staff_emission');
            }
        }
        foreach($expected as $name=>$value) {
            ($this->writeHeader)($name.': '.$value);
        }
        if ($cookie!==null &&
            ($this->writeCookie)(
                CentralStaffHttpPolicy::COOKIE_NAME,
                $cookie['value'],$cookie['options'])!==true) {
            throw new RegistrationError('staff_cookie_write_failed');
        }
        ($this->writeBody)($body);
    }
}
