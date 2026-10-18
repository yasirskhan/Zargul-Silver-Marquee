<?php

namespace App\Services;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Explicit owner/admin manual Verify. Never calls central on page load.
 * Caller must already be an authenticated verified company owner/admin;
 * authorization and immutable installation binding are repeated here.
 */
final class CentralOwnerManualLicenseVerification
{
    public function __construct(
        private readonly CentralInstallationCompanyScope $scope,
        private readonly CentralInstallationStatusTransport $transport,
        private readonly CentralLicenseCheckSchedule $schedule
    ) {}

    /** @return array{state:string} */
    public function verify(User $actor, string $companyId, DateTimeImmutable $now): array
    {
        if (config('central_control.activation_enabled') !== true ||
            config('central_control.manual_checks_enabled') !== true) {
            throw new AuthorizationException('Manual licensing has not been released.');
        }
        $this->scope->assertCanRead($actor,$companyId);
        $reserved=DB::transaction(function () use ($actor,$companyId): bool {
            DB::select('SELECT pg_advisory_xact_lock(708116, 1116)');
            $this->scope->assertCanRead($actor,$companyId);
            $binding=DB::table('central_installation_activation')
                ->where('singleton_id',1)->lockForUpdate()->first();
            if (! $binding || $binding->company_id !== $companyId ||
                $binding->status !== 'registered_pending_license') {
                throw new AuthorizationException('Another company owns this installation.');
            }
            // The trusted database clock is authoritative; a browser-supplied
            // clock or timezone must never bypass the manual cooldown.
            $tooSoon=DB::table('central_manual_license_check_attempts')
                ->where('installation_id',$binding->installation_id)
                ->where('requested_at','>',now()->subMinute())->exists();
            if ($tooSoon) return false;
            DB::table('central_manual_license_check_attempts')->insert([
                'id'=>(string)Str::uuid(),
                'installation_id'=>$binding->installation_id,
                'company_id'=>$companyId,
                'requested_by'=>$actor->getKey(),
                'requested_at'=>now(),
            ]);
            return true;
        });
        if (! $reserved) {
            return ['state'=>'rate_limited'];
        }
        try {
            $result=$this->transport->check($now);
            if ($result['licensed'] === true) {
                return ['state'=>'verified'];
            }
            if (($result['requires_license_attention'] ?? false) === true) {
                return ['state'=>'license_attention'];
            }
            // An unsigned/receipt-only status NEVER advances last-success.
            return ['state'=>'pending_signed_license'];
        } catch (ConnectionException) {
            $this->schedule->recordFailure($now,'network');
            return ['state'=>'unavailable'];
        } catch (InvalidArgumentException) {
            $this->schedule->recordFailure($now,'invalid_response');
            return ['state'=>'unavailable'];
        } catch (Throwable) {
            // Fail closed, with no secrets in browser or log messages.
            $this->schedule->recordFailure($now,'server');
            return ['state'=>'unavailable'];
        }
    }
}
