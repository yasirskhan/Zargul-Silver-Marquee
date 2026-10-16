<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal, read-only hall calendar projection. This is not a booking or public
 * availability API: only the atomic claim service can decide if a slot is free.
 */
final class HallOccupancyCalendarService
{
    private const PROGRAMS = ['morning', 'noon', 'evening'];

    public function __construct(private readonly TenantAccess $access) {}

    /**
     * Inclusive date range of at most six weeks (month-grid friendly).
     * No claim IDs, staff identities, customer data, prices or audit context
     * leave this projection.
     */
    public function forHall(
        User $actor, string $companyId, string $hallId,
        string $fromDate, string $toDate,
    ): array {
        $hall = Hall::query()->where('company_id', $companyId)
            ->where('id', $hallId)->where('is_active', true)->first();

        if (! $hall || ! Auth::check()
            || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            || ! $this->access->canViewHall($actor, $hall)) {
            throw new AuthorizationException('You cannot view this hall calendar.');
        }

        $start = $this->parseDate($fromDate);
        $end = $this->parseDate($toDate);
        $dayCount = (int) $start->diff($end)->format('%r%a') + 1;
        if ($dayCount < 1 || $dayCount > 42) {
            throw new InvalidArgumentException('Calendar must cover 1 to 42 consecutive dates.');
        }

        $asOf = now();
        $claims = DB::table('hall_slot_claims')
            ->where('company_id', $companyId)->where('hall_id', $hallId)
            ->whereBetween('event_date', [$fromDate, $toDate])
            ->get(['event_date', 'program', 'status', 'expires_at']);

        // The physical unique constraint guarantees at most one claim per slot.
        $byDate = [];
        foreach ($claims as $claim) {
            $byDate[$claim->event_date][$claim->program] = $this->status($claim, $asOf);
        }

        $configured = (int) $hall->program_rules_revision > 0;
        $days = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $programs = [];
            foreach (self::PROGRAMS as $program) {
                $programs[$program] = $byDate[$date][$program]
                    ?? ['status' => $configured ? 'available' : 'unconfigured'];
            }
            $days[] = ['date' => $date, 'programs' => $programs];
        }

        return [
            'companyId' => $companyId, 'hallId' => $hallId,
            'fromDate' => $fromDate, 'toDate' => $toDate,
            'asOf' => $asOf->toIso8601String(),
            'configured' => $configured, 'days' => $days,
        ];
    }

    private function parseDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Calendar date must be a real YYYY-MM-DD date.');
        }

        return $parsed;
    }

    private function status(object $claim, Carbon $asOf): array
    {
        if ($claim->status === 'confirmed') {
            return ['status' => 'confirmed'];
        }

        // An expired row still occupies its unique key. Do not imply it can
        // be booked until an audited hold service transaction releases it.
        $expiry = $claim->expires_at ? Carbon::parse($claim->expires_at) : null;
        if ($claim->status !== 'hold' || $expiry === null
            || $expiry->lessThanOrEqualTo($asOf)) {
            return ['status' => 'cleanup_required'];
        }

        return ['status' => 'hold', 'expiresAt' => $expiry->toIso8601String()];
    }
}
