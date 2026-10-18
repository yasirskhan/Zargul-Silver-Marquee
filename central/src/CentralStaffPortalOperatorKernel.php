<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * INTERNAL future operator-vhost request composition, NOT a public SAPI
 * entry point. Requires BOTH explicit environment release and trusted
 * constructor enablement. Does not instantiate credential/KMS secrets,
 * register any public route, echo a response or serialize login credentials.
 *
 * A separate operator-owned deployment must inject a vault-backed MFA
 * verifier, HMAC throttle key, trusted TLS/proxy and secure cookie emitter.
 */
final class CentralStaffPortalOperatorKernel
{
    public function __construct(
        private readonly CentralStaffPortalHttpRouter $router,
        private readonly CentralStaffPortalPrivateResponse $writer,
        private readonly bool $enabled=false
    ) {}

    /** @return array{status:int,headers:array<string,string>,cookies:array<int,array<string,mixed>>,body:string} */
    public function handle(array $server,array $cookies,string $rawBody): array
    {
        if (!$this->enabled ||
            getenv('CENTRAL_STAFF_PORTAL_ENABLED')!=='true') {
            throw new RegistrationError('staff_operator_not_enabled',503);
        }
        $fields=CentralStaffPortalFormRequest::decode($server,$rawBody);
        $header=$server['HTTP_X_STAFF_CSRF']??null;
        $form=$fields['staff_csrf']??null;
        if (($header!==null && !is_string($header)) ||
            ($form!==null && !is_string($form)) ||
            ($header!==null && $form!==null &&
                !hash_equals($header,$form))) {
            throw new RegistrationError('staff_form_denied');
        }
        unset($fields['staff_csrf']);
        $proof=$header??$form;
        return $this->writer->compose(
            $this->router->dispatch($server,$cookies,$fields,$proof));
    }
}
