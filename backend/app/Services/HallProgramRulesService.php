<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class HallProgramRulesService
{
    private const PROGRAMS = ['morning', 'noon', 'evening'];

    private const FIELDS = [
        'start', 'end', 'setupMinutes', 'cleaningMinutes', 'graceMinutes',
        'overtimeIntervalMinutes', 'overtimeRateMinor', 'rentalRateMinor',
    ];

    public function __construct(private readonly TenantAccess $access) {}

    /**
     * Version all three owner-configured daily hall programs as one immutable
     * change. Times are LOCAL same-day HH:MM; actual date/zone bookings follow
     * in Phase 3. Overtime is NOT automatically a right to extend a booking.
     */
    public function configure(
        User $actor, string $companyId, string $hallId,
        array $rules, string $currencyCode, int $expectedRevision, string $reason,
    ): int {
        // Deny outsiders before disclosing detailed validation errors.
        $candidate = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        if (! $candidate || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail() || ! $this->access->canConfigureHall($actor, $candidate)) {
            throw new AuthorizationException('You cannot change this hall schedule or pricing.');
        }
        $this->validateRules($rules, $currencyCode);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A reason for schedule or price changes is required.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $hallId, $rules, $currencyCode, $expectedRevision, $reason
        ): int {
            $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
                ->where('is_active', true)->lockForUpdate()->first();
            if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
                || ! $actor->hasVerifiedEmail() || ! $this->access->canConfigureHall($actor, $hall)) {
                throw new AuthorizationException('You cannot change this hall schedule or pricing.');
            }

            $live = (int) $hall->program_rules_revision;
            if ($expectedRevision < 0 || $live !== $expectedRevision) {
                throw new ConflictHttpException('Hall program rules changed; reload before saving.');
            }

            $before = $live > 0
                ? DB::table('hall_program_rule_revisions')
                    ->where('company_id', $companyId)->where('hall_id', $hallId)
                    ->where('revision_number', $live)->first(['rules', 'currency_code'])
                : null;
            if ($live > 0 && ! $before) {
                throw new ConflictHttpException('The previous hall configuration is missing.');
            }
            $next = $live + 1;
            $id = (string) Str::uuid();
            DB::table('hall_program_rule_revisions')->insert([
                'id' => $id, 'company_id' => $companyId, 'hall_id' => $hallId,
                'revision_number' => $next, 'currency_code' => $currencyCode,
                'rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                'changed_by' => $actor->getKey(), 'reason' => $reason,
            ]);
            DB::table('halls')->where('company_id', $companyId)->where('id', $hallId)
                ->update(['program_rules_revision' => $next, 'updated_at' => now()]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'hall.program_rules_updated',
                'entity_type' => 'hall', 'entity_id' => $hallId,
                'before_values' => json_encode([
                    'revision' => $live,
                    'currency' => $before?->currency_code,
                    'rules' => $before ? json_decode($before->rules, true, 512, JSON_THROW_ON_ERROR) : null,
                ], JSON_THROW_ON_ERROR),
                'after_values' => json_encode([
                    'revision' => $next, 'revision_id' => $id,
                    'currency' => $currencyCode, 'rules' => $rules,
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);

            return $next;
        }, 3);
    }

    /**
     * Staff must have explicit hall visibility before viewing its rules.
     * No initial configuration means this hall is not available for booking.
     */
    public function current(User $actor, string $companyId, string $hallId): ?array
    {
        $hall = Hall::query()->where('company_id', $companyId)
            ->where('id', $hallId)->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail() || ! $this->access->canViewHall($actor, $hall)) {
            throw new AuthorizationException('This hall is not accessible.');
        }
        $revision = (int) $hall->program_rules_revision;
        if ($revision === 0) {
            return null;
        }
        $saved = DB::table('hall_program_rule_revisions')->where('company_id', $companyId)
            ->where('hall_id', $hallId)->where('revision_number', $revision)->first();
        if (! $saved) {
            throw new ConflictHttpException('Hall program rules need administrative repair.');
        }

        return [
            'revision' => $revision,
            'currencyCode' => $saved->currency_code,
            'rules' => json_decode($saved->rules, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function validateRules(array $rules, string $currencyCode): void
    {
        if (! preg_match('/\A[A-Z]{3}\z/', $currencyCode)
            || array_is_list($rules) || count($rules) !== 3
            || array_diff(array_keys($rules), self::PROGRAMS)
            || array_diff(self::PROGRAMS, array_keys($rules))) {
            throw new InvalidArgumentException('Configure exactly three programs and an uppercase currency code.');
        }

        $previousClear = 0;
        foreach (self::PROGRAMS as $name) {
            $slot = $rules[$name];
            if (! is_array($slot) || array_is_list($slot)
                || count($slot) !== count(self::FIELDS)
                || array_diff(array_keys($slot), self::FIELDS)
                || array_diff(self::FIELDS, array_keys($slot))) {
                throw new InvalidArgumentException('Each program needs all approved time and price fields.');
            }
            $start = $this->minutes($slot['start']);
            $end = $this->minutes($slot['end']);
            foreach (['setupMinutes', 'cleaningMinutes', 'graceMinutes',
                'overtimeIntervalMinutes', 'overtimeRateMinor', 'rentalRateMinor'] as $field) {
                if (! is_int($slot[$field]) || $slot[$field] < 0) {
                    throw new InvalidArgumentException('Hall time and price amounts must be nonnegative integers.');
                }
            }
            if ($slot['setupMinutes'] > 240 || $slot['cleaningMinutes'] > 240
                || $slot['graceMinutes'] > 120
                || $slot['overtimeIntervalMinutes'] < 1 || $slot['overtimeIntervalMinutes'] > 120
                || $slot['overtimeRateMinor'] > 100_000_000_000
                || $slot['rentalRateMinor'] > 100_000_000_000
                || $end <= $start || $end - $start > 16 * 60) {
                throw new InvalidArgumentException('One or more program time/price limits are invalid.');
            }

            $availableFrom = $start - $slot['setupMinutes'];
            $clearBy = $end + $slot['cleaningMinutes'];
            if ($availableFrom < $previousClear || $clearBy > 1440) {
                throw new InvalidArgumentException('Daily programs overlap or exceed midnight when setup and cleaning are included.');
            }
            $previousClear = $clearBy;
        }
    }

    private function minutes(mixed $clock): int
    {
        if (! is_string($clock) || ! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $clock)) {
            throw new InvalidArgumentException('Program times must be in 24-hour HH:MM format.');
        }

        return (int) substr($clock, 0, 2) * 60 + (int) substr($clock, 3, 2);
    }
}
