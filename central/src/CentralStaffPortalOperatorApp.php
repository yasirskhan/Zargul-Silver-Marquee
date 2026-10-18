<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Operator-owned application runner, NOT registered by central/public.
 * Never logs or JSON-serializes bearer, cookie or CSRF material. All trusted
 * SAPI header/cookie/body writers come from the operator's protected vhost.
 */
final class CentralStaffPortalOperatorApp
{
    public function __construct(
        private readonly CentralStaffPortalOperatorKernel $kernel,
        private readonly CentralStaffPortalResponseEmitter $emitter
    ) {}

    /** @param array<string,mixed> $server @param array<string,mixed> $cookies */
    public function serve(array $server,array $cookies,string $rawFormBody):void
    {
        if(getenv('CENTRAL_STAFF_BOOTSTRAP_ENABLED')!=='true') {
            throw new RegistrationError('staff_bootstrap_not_enabled',503);
        }
        $reply=$this->kernel->handle($server,$cookies,$rawFormBody);
        $this->emitter->emit($reply);
    }
}
