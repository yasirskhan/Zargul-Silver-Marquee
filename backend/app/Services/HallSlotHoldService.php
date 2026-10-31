<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Internal hold foundation only. Never confirms a booking, prices a quote,
 * collects a deposit, or exposes a public booking route.
 *
 * The hall row is locked BEFORE querying the unique active claim, serializing
 * concurrent internal requests even when an expired hold must be replaced.
 */
final class HallSlotHoldService
{
    private const PROGRAMS = ['morning', 'noon', 'evening'];

    public function __construct(private readonly TenantAccess $access) {}

    public function placeHold(
        User $actor, string $companyId, string $hallId,
        string $eventDate, string $program, string $claimId,
        int $durationMinutes, string $reason,
    ): array {
        $this->checkActor($actor, $companyId, $hallId);
        if (! $this->validDate($eventDate) || ! in_array($program, self::PROGRAMS, true)
            || ! Str::isUuid($claimId) || $durationMinutes < 1 || $durationMinutes > 120) {
            throw new InvalidArgumentException('The slot, claim key, or temporary hold duration is invalid.');
        }
        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $actor, $companyId, $hallId, $eventDate, $program, $claimId, $durationMinutes, $reason
        ): array {
            $hall = $this->lockedHall($actor, $companyId, $hallId);
            if ((int) $hall->program_rules_revision === 0) {
                throw new ConflictHttpException('Hall programs must be configured before creating a hold.');
            }

            $now = now();
            $existing = DB::table('hall_slot_claims')->where('company_id', $companyId)
                ->where('hall_id', $hallId)->where('event_date', $eventDate)
                ->where('program', $program)->lockForUpdate()->first();

            if ($existing) {
                $expiresAt = $existing->expires_at ? Carbon::parse($existing->expires_at) : null;
                if ($existing->id === $claimId && $existing->status === 'hold'
                    && (int) $existing->created_by === (int) $actor->getKey()
                    && $expiresAt && $expiresAt->greaterThan($now)) {
                    // Exact retry of the same actor/key/slot does not renew the hold
                    // or add another event/audit record.
                    return $this->result($existing->id, $eventDate, $program, $expiresAt);
                }

                if ($existing->status !== 'hold' || ! $expiresAt || $expiresAt->greaterThan($now)) {
                    throw new ConflictHttpException('This hall program is already reserved or on hold.');
                }
                $before = $this->snapshot($existing);
                $this->record($actor, $companyId, $hallId, $eventDate, $program, $existing->id,
                    'hold_expired', $before, null, 'Expired hold released before a new request');
                DB::table('hall_slot_claims')->where('id', $existing->id)
                    ->where('company_id', $companyId)->where('hall_id', $hallId)->delete();
            }

            if (DB::table('public_booking_holds')->where('company_id', $companyId)
                ->where('hall_id', $hallId)->where('event_date', $eventDate)
                ->where('program', $program)->where('expires_at', '>', now())->exists()) {
                throw new ConflictHttpException('A public customer hold owns this slot.');
            }
            $expiresAt = $now->copy()->addMinutes($durationMinutes);
            DB::table('hall_slot_claims')->insert([
                'id' => $claimId, 'company_id' => $companyId, 'hall_id' => $hallId,
                'event_date' => $eventDate, 'program' => $program, 'status' => 'hold',
                'expires_at' => $expiresAt, 'created_by' => $actor->getKey(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $after = [
                'status' => 'hold', 'expiresAt' => $expiresAt->toIso8601String(),
                'createdBy' => $actor->getKey(),
            ];
            $this->record($actor, $companyId, $hallId, $eventDate, $program, $claimId,
                'hold_created', null, $after, $reason);

            return $this->result($claimId, $eventDate, $program, $expiresAt);
        }, 3);
    }

    /** Only the actor who created a temporary hold may release it at this stage. */
    public function releaseOwnHold(
        User $actor, string $companyId, string $hallId, string $claimId, string $reason,
    ): bool {
        $this->checkActor($actor, $companyId, $hallId);
        if (! Str::isUuid($claimId)) {
            throw new InvalidArgumentException('Hold identifier must be a UUID.');
        }
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $companyId, $hallId, $claimId, $reason): bool {
            $this->lockedHall($actor, $companyId, $hallId);
            $claim = DB::table('hall_slot_claims')->where('company_id', $companyId)
                ->where('hall_id', $hallId)->where('id', $claimId)->lockForUpdate()->first();
            if (! $claim) {
                return false;
            }
            if ($claim->status !== 'hold' || (int) $claim->created_by !== (int) $actor->getKey()) {
                throw new AuthorizationException('You cannot release this hall claim.');
            }
            $this->record($actor, $companyId, $hallId, $claim->event_date, $claim->program,
                $claim->id, 'hold_released', $this->snapshot($claim), null, $reason);
            DB::table('hall_slot_claims')->where('company_id', $companyId)
                ->where('hall_id', $hallId)->where('id', $claimId)->delete();

            return true;
        }, 3);
    }

    private function lockedHall(User $actor, string $companyId, string $hallId): Hall
    {
        $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->lockForUpdate()->first();
        $this->authorize($actor, $hall);

        return $hall;
    }

    private function checkActor(User $actor, string $companyId, string $hallId): void
    {
        $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        $this->authorize($actor, $hall);
    }

    private function authorize(User $actor, ?Hall $hall): void
    {
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail() || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('You cannot place or release a hold for this hall.');
        }
    }

    private function validDate(string $date): bool
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $value !== false && $value->format('Y-m-d') === $date;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A reason is required for each hold change.');
        }

        return $reason;
    }

    private function snapshot(object $claim): array
    {
        return [
            'status' => $claim->status,
            'expiresAt' => $claim->expires_at ? Carbon::parse($claim->expires_at)->toIso8601String() : null,
            'createdBy' => (int) $claim->created_by,
        ];
    }

    private function result(string $id, string $date, string $program, Carbon $expiresAt): array
    {
        return [
            'claimId' => $id, 'date' => $date, 'program' => $program,
            'status' => 'hold', 'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    private function record(
        User $actor, string $companyId, string $hallId, string $eventDate, string $program,
        string $claimId, string $action, ?array $before, ?array $after, string $reason,
    ): void {
        DB::table('hall_slot_claim_events')->insert([
            'id' => (string) Str::uuid(), 'claim_id' => $claimId,
            'company_id' => $companyId, 'hall_id' => $hallId,
            'event_date' => $eventDate, 'program' => $program, 'action' => $action,
            'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_id' => $actor->getKey(), 'reason' => $reason,
        ]);
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(), 'action' => 'hall_slot.'.$action,
            'entity_type' => 'hall_slot_claim', 'entity_id' => $claimId,
            'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode([
                'reason' => $reason, 'hallId' => $hallId, 'eventDate' => $eventDate,
                'program' => $program,
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
