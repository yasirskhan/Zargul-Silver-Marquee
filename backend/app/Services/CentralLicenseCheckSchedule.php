<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only due evaluation and local check attempt bookkeeping. Never
 * contacts the vendor or rewrites a signed license. Only the future
 * authenticated HTTPS check-in service may call recordVerifiedSuccess.
 *
 * Network outages never fabricate revocation, expiry or renewed entitlement.
 */
final class CentralLicenseCheckSchedule
{
    public function __construct(private readonly CentralLicenseStatusPolicy $policy) {}

    public function isDue(DateTimeImmutable $now, bool $manual = false): bool
    {
        $identity = DB::table('central_installation_identity')->where('singleton_id', 1)->first();
        if (! $identity || config('central_control.activation_enabled') !== true) return false;
        $state = DB::table('central_license_check_state')->where('singleton_id', 1)->first();
        if ($manual) return true; // Manual Verify bypasses 15-day routine cadence, not separate UI rate limiting.
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        if ($state?->next_retry_at &&
            $now < new DateTimeImmutable($state->next_retry_at)) return false;
        $last = $state?->last_online_verified_at
            ? new DateTimeImmutable($state->last_online_verified_at) : null;
        // A clock rollback must not postpone a central check for months/years.
        if ($last !== null && $now < $last->modify('-5 minutes')) return true;
        return $now >= $this->policy->nextCentralVerification($last, $now);
    }

    public function recordFailure(DateTimeImmutable $at, string $category): void
    {
        if (! in_array($category, ['network', 'server', 'invalid_response'], true)) {
            throw new InvalidArgumentException('Only sanitized retry categories can be stored.');
        }
        $this->mutate(function (array $state) use ($at, $category): array {
            $attempts = min(31, $state['consecutive_failures'] + 1);
            // First retry in 60s, doubles, bounded to one day.
            $seconds = min(86400, 60 * (2 ** ($attempts - 1)));
            $next = $at->setTimezone(new DateTimeZone('UTC'))
                ->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:sP');
            return [
                'consecutive_failures'=>$attempts,
                'last_failure_category'=>$category,
                'last_attempted_at'=>$at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
                'next_retry_at'=>$next,
            ];
        });
    }

    /**
     * TRUST BOUNDARY: call only after successful authenticated central
     * response, not after a local signature check or received HTTP 200 alone.
     */
    public function recordVerifiedSuccess(DateTimeImmutable $at): void
    {
        $this->mutate(function (array $state) use ($at): array {
            $last = $state['last_online_verified_at']
                ? new DateTimeImmutable($state['last_online_verified_at']) : null;
            if ($last && $at->setTimezone(new DateTimeZone('UTC')) < $last) {
                throw new InvalidArgumentException('Successful central check timestamp cannot regress.');
            }
            return [
            'consecutive_failures'=>0,
            'last_failure_category'=>null,
            'last_attempted_at'=>$at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
            'last_online_verified_at'=>$at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
            'next_retry_at'=>null,
            ];
        });
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $f */
    private function mutate(callable $f): void
    {
        if (config('central_control.activation_enabled') !== true) {
            throw new InvalidArgumentException('Central verification is not enabled.');
        }
        DB::transaction(function () use ($f): void {
            DB::select('SELECT pg_advisory_xact_lock(708116, 1113)');
            $identity = DB::table('central_installation_identity')->where('singleton_id', 1)->first();
            if (! $identity) throw new InvalidArgumentException('Installation identity missing.');
            $record = DB::table('central_license_check_state')
                ->where('singleton_id', 1)->lockForUpdate()->first();
            $before = [
                'consecutive_failures'=>(int) ($record?->consecutive_failures ?? 0),
                'last_online_verified_at'=>$record?->last_online_verified_at,
            ];
            $values = $f($before);
            $values['updated_at']=now();
            if ($record) {
                DB::table('central_license_check_state')->where('singleton_id', 1)->update($values);
            } else {
                DB::table('central_license_check_state')->insert([
                    'singleton_id'=>1,
                    'installation_id'=>$identity->installation_id,
                    'created_at'=>now(),
                ] + $values);
            }
        });
    }
}
