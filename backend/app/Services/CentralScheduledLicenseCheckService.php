<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Due-only AUTOMATIC check execution. This is not a five-minute polling loop
 * and is intentionally separate from the operator-controlled manual Verify
 * workflow. Only a matching signed grant+vendor receipt in the transport may
 * update last_online_verified_at. Missing/unsigned responses are not renewals.
 */
final class CentralScheduledLicenseCheckService
{
    public function __construct(
        private readonly CentralLicenseCheckSchedule $schedule,
        private readonly CentralInstallationStatusTransport $transport
    ) {}

    /** @return array{state:string} */
    public function run(DateTimeImmutable $now): array
    {
        if (config('central_control.activation_enabled') !== true ||
            config('central_control.scheduled_checks_enabled') !== true) {
            return ['state'=>'disabled'];
        }
        $binding=DB::table('central_installation_activation')
            ->where('singleton_id',1)->first();
        if (! $binding || $binding->company_id === null ||
            $binding->status !== 'registered_pending_license') {
            return ['state'=>'not_enrolled'];
        }
        if (! $this->schedule->isDue($now)) {
            return ['state'=>'not_due'];
        }
        try {
            $result=$this->transport->check($now);
            if ($result['licensed'] !== true) {
                // Receipt-only status and an unsigned pending grant must never
                // count as successful online license verification.
                $this->schedule->recordFailure($now,'invalid_response');
                return ['state'=>'pending_signed_license'];
            }
            // Transport has atomically accepted BOTH pinned signatures and
            // recorded the verified success timestamp within grant delivery.
            return ['state'=>'signed_license_verified'];
        } catch (ConnectionException) {
            $this->schedule->recordFailure($now,'network');
            return ['state'=>'error'];
        } catch (InvalidArgumentException) {
            $this->schedule->recordFailure($now,'invalid_response');
            return ['state'=>'error'];
        } catch (Throwable) {
            // Do not expose vendor errors, enrollment token, receipt or key
            // material in stdout or the owner's browser.
            $this->schedule->recordFailure($now,'server');
            return ['state'=>'error'];
        }
    }
}
