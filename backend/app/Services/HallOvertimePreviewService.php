<?php

namespace App\Services;

use App\Models\User;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Nonbinding same-day clock simulation of owner-approved session overtime.
 * Never records an actual event, extends a slot, issues a bill or posts money.
 */
final class HallOvertimePreviewService
{
    private const PROGRAMS = ['morning', 'noon', 'evening'];

    public function __construct(private readonly HallProgramRulesService $rules) {}

    public function preview(
        User $actor, string $companyId, string $hallId,
        string $program, string $observedEnd,
    ): array {
        // Reuse the current live, verified, assigned-hall read policy.
        $saved = $this->rules->current($actor, $companyId, $hallId);
        if ($saved === null) {
            throw new ConflictHttpException('A saved hall program revision is required.');
        }
        $index = array_search($program, self::PROGRAMS, true);
        if ($index === false || ! isset($saved['rules'][$program])) {
            throw new InvalidArgumentException('Select a valid configured daily program.');
        }
        $rule = $saved['rules'][$program];
        $start = $this->minute((string) ($rule['start'] ?? ''));
        $end = $this->minute((string) ($rule['end'] ?? ''));
        $actual = $this->minute($observedEnd);
        if ($actual < $start || $end <= $start) {
            throw new InvalidArgumentException('End-time simulation must occur after program start.');
        }
        foreach (['cleaningMinutes', 'graceMinutes', 'overtimeIntervalMinutes', 'overtimeRateMinor'] as $name) {
            if (! isset($rule[$name]) || ! is_int($rule[$name]) || $rule[$name] < 0) {
                throw new ConflictHttpException('Stored program overtime policy is invalid.');
            }
        }
        if ($rule['overtimeIntervalMinutes'] < 1) {
            throw new ConflictHttpException('Stored overtime billing interval is invalid.');
        }

        $hardStop = 1440 - $rule['cleaningMinutes'];
        if ($index < count(self::PROGRAMS) - 1) {
            $next = $saved['rules'][self::PROGRAMS[$index + 1]] ?? null;
            if (! is_array($next) || ! isset($next['setupMinutes'])
                || ! is_int($next['setupMinutes'])) {
                throw new ConflictHttpException('Next program turnover policy is missing.');
            }
            $hardStop = min($hardStop, $this->minute((string) $next['start'])
                - $next['setupMinutes'] - $rule['cleaningMinutes']);
        }
        if ($hardStop < $end) {
            throw new ConflictHttpException('Stored hall program turnover constraints are inconsistent.');
        }

        $overrun = max(0, $actual - $end);
        $chargeable = max(0, $overrun - $rule['graceMinutes']);
        $intervals = $chargeable === 0 ? 0
            : intdiv($chargeable + $rule['overtimeIntervalMinutes'] - 1, $rule['overtimeIntervalMinutes']);
        $projected = $intervals * $rule['overtimeRateMinor'];
        if ($projected > 100000000000) {
            throw new ConflictHttpException('Projected overtime exceeds the internal preview limit.');
        }
        return [
            'status' => 'internal_nonbinding',
            'companyId' => $companyId, 'hallId' => $hallId,
            'program' => $program, 'sourceRevision' => $saved['revision'],
            'currency' => $saved['currencyCode'],
            'scheduledEnd' => $rule['end'], 'observedEnd' => $observedEnd,
            'graceMinutes' => $rule['graceMinutes'],
            'overtimeMinutes' => $overrun,
            'chargeableMinutes' => $chargeable,
            'startedIntervals' => $intervals,
            'projectedAmountMinor' => $projected,
            'hardStopClock' => sprintf('%02d:%02d', intdiv($hardStop, 60), $hardStop % 60),
            'conflictsWithTurnover' => $actual > $hardStop,
            'extensionAuthorized' => false,
        ];
    }

    private function minute(string $time): int
    {
        if (! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time)) {
            throw new InvalidArgumentException('Use a same-day 24-hour HH:MM clock time.');
        }
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
