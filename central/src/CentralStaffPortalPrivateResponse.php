<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * INTERNAL ONLY, separately default-disabled response composition for a
 * future protected HAFZATECH staff HTTP controller. NOT a public emitter.
 *
 * A trusted vhost must apply the returned headers and Set-Cookie instructions
 * without serializing this internal array as JSON. In particular, the session
 * bearer only goes in a Secure HttpOnly host-only cookie, never page content,
 * logs, query strings or an unprotected JSON response. CSRF is an independent
 * per-session proof for same-origin form/header use.
 */
final class CentralStaffPortalPrivateResponse
{
    public function __construct(private readonly bool $enabled=false) {}

    /**
     * @param array<string,mixed> $internal Output from the protected router
     * @return array{status:int,headers:array<string,string>,cookies:array<int,array<string,mixed>>,body:string}
     */
    public function compose(array $internal): array
    {
        if (!$this->enabled) {
            throw new RegistrationError('staff_response_not_enabled',503);
        }
        $kind=$internal['kind']??null;
        $headers=[
            'Content-Type'=>'text/html; charset=utf-8',
            'Cache-Control'=>'no-store, private',
            'X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer',
            'X-Frame-Options'=>'DENY',
            'Content-Security-Policy'=>
                "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
        ];

        if ($kind==='private_customer_directory_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH customer directory">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_staff_home_html') {
            $html=$internal['html']??null;
            if (!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH staff home">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_vendor_plan_catalog_html' ||
            $kind==='private_vendor_plan_published_html') {
            $html=$internal['html']??null;
            $prefix=$kind==='private_vendor_plan_catalog_html'
                ? '<section aria-label="Private HAFZATECH plan catalog">'
                : '<section aria-label="Private HAFZATECH plan publication receipt">';
            if(!is_string($html) || strlen($html)>262144 ||
               !str_starts_with($html,$prefix)) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_vendor_invoice_directory_html') {
            $html=$internal['html']??null;
            if(!is_string($html)||strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH vendor invoices">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_vendor_invoice_issued_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH vendor invoice receipt">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_staff_activation_readiness_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH activation readiness">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_staff_provisioned_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH staff pending account">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_team_dashboard_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH team dashboard">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_support_reply_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH support reply receipt">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_staff_roles_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH staff roles">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_staff_directory_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH staff directory">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_role_request_receipt_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH role request receipt">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_staff_role_decision_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH role decision receipt">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_role_review_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH role review queue">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_auditor_home_html') {
            $html=$internal['html']??null;
            if(!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH auditor dashboard">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_audit_html') {
            $html=$internal['html']??null;
            if (!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH staff audit">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_support_html') {
            $html=$internal['html']??null;
            if (!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH support queue">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_dashboard_html') {
            $html=$internal['html']??null;
            if (!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH staff dashboard">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_customer_html') {
            $html=$internal['html']??null;
            if (!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH customer lookup">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_license_html') {
            $html=$internal['html']??null;
            if (!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH licensing queue">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='private_html') {
            $html=$internal['html']??null;
            if (!is_string($html) || strlen($html)>262144 ||
                !str_starts_with($html,
                    '<section aria-label="Private HAFZATECH billing status">')) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[],'body'=>$html];
        }
        if ($kind==='internal_login') {
            $creds=$internal['credentials']??null;
            if (!is_array($creds) ||
                ($creds['cookie_name']??null)!==CentralStaffHttpPolicy::COOKIE_NAME ||
                !is_string($creds['cookie_value']??null) ||
                preg_match('/^[a-f0-9]{64}$/D',$creds['cookie_value'])!==1 ||
                !is_string($creds['csrf']??null) ||
                preg_match('/^[a-f0-9]{64}$/D',$creds['csrf'])!==1 ||
                !self::secureOptions($creds['cookie_options']??null,false)) {
                throw new RegistrationError('invalid_staff_response');
            }
            // Only this independent CSRF proof is provided to the private
            // same-origin document. The HttpOnly bearer NEVER enters HTML.
            $body='<!doctype html><html lang="en"><head>'.
                '<meta charset="utf-8"><title>HAFZATECH staff</title>'.
                '<meta name="staff-csrf-token" content="'.$creds['csrf'].'">'.
                '</head><body><main><h1>HAFZATECH staff</h1>'.
                '<p>Authenticated. Private staff functions require separate '.
                'current role and customer authorization.</p>'.
                '<form method="post" action="/staff/home">'.
                '<input type="hidden" name="staff_csrf" value="'.$creds['csrf'].'">'.
                '<button type="submit">Open Central Control Center</button>'.
                '</form></main></body></html>';
            return ['status'=>200,'headers'=>$headers,'cookies'=>[[
                'name'=>CentralStaffHttpPolicy::COOKIE_NAME,
                'value'=>$creds['cookie_value'],
                'options'=>$creds['cookie_options']
            ]],'body'=>$body];
        }
        if ($kind==='internal_logout') {
            if (($internal['clear_cookie_name']??null)!==
                    CentralStaffHttpPolicy::COOKIE_NAME ||
                !self::secureOptions($internal['clear_cookie_options']??null,true)) {
                throw new RegistrationError('invalid_staff_response');
            }
            return ['status'=>200,'headers'=>$headers,'cookies'=>[[
                'name'=>CentralStaffHttpPolicy::COOKIE_NAME,
                'value'=>'',
                'options'=>$internal['clear_cookie_options']
            ]],'body'=>'<!doctype html><html lang="en"><head>'.
                '<meta charset="utf-8"><title>Signed out</title>'.
                '</head><body><main><h1>Signed out</h1></main></body></html>'];
        }
        throw new RegistrationError('invalid_staff_response');
    }

    private static function secureOptions(mixed $options,bool $clearing): bool
    {
        if (!is_array($options) ||
            ($options['path']??null)!=='/' ||
            ($options['secure']??null)!==true ||
            ($options['httponly']??null)!==true ||
            ($options['samesite']??null)!=='Strict' ||
            !is_int($options['expires']??null)) {
            return false;
        }
        if ($clearing)return $options['expires']===1;
        $now=time();
        return $options['expires']>$now &&
            $options['expires']<=$now+43260;
    }
}
