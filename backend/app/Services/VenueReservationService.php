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
 * Internal, release-gated booking lifecycle: reviewed proposal -> exclusive
 * confirmed slot; reschedule or cancellation. No customer invoice or payment.
 *
 * Every mutation serializes on the company/hall row before inspecting or
 * modifying the unique active hall/date/program claim. Every event is audited
 * in the SAME PostgreSQL transaction as the physical occupancy change.
 */
final class VenueReservationService
{
    public function __construct(private readonly TenantAccess $access) {}

    private function gate(): void
    {
        if (config('booking_controls.confirmation_enabled') !== true) {
            throw new ConflictHttpException('Reservation confirmation is disabled until venue release approval.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $reason)) {
            throw new InvalidArgumentException('A plain operational reason of 3-500 characters is required.');
        }
        return $reason;
    }

    private function date(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid booking date.');
        }
    }

    private function authorizeHall(User $actor, string $companyId, string $hallId, bool $lock = false): Hall
    {
        $query = Hall::query()->where('company_id', $companyId)
            ->where('id', $hallId)->where('is_active', true);
        $hall = $lock ? $query->lockForUpdate()->first() : $query->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('Current staff has no permission to manage this hall reservation.');
        }
        return $hall;
    }

    private function bookingSnapshot(object $row): array
    {
        return [
            'id' => $row->id, 'sourceDraftId' => $row->source_draft_id,
            'hallId' => $row->hall_id, 'customerId' => $row->customer_id,
            'date' => $row->event_date, 'program' => $row->program,
            'guests' => (int) $row->guests, 'claimId' => $row->claim_id,
            'status' => $row->status, 'revision' => (int) $row->revision,
        ];
    }

    private function record(
        User $actor, string $companyId, string $reservationId,
        string $action, int $revision, ?array $before, array $after, string $reason,
    ): void {
        DB::table('venue_reservation_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'reservation_id' => $reservationId, 'revision' => $revision,
            'action' => $action, 'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_id' => $actor->getKey(), 'reason' => $reason,
        ]);
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(), 'action' => 'reservation.'.$action,
            'entity_type' => 'venue_reservation', 'entity_id' => $reservationId,
            'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }

    private function claimEvent(
        User $actor, string $companyId, string $hallId, string $date,
        string $program, string $claimId, string $action,
        ?array $before, ?array $after, string $reason,
    ): void {
        DB::table('hall_slot_claim_events')->insert([
            'id' => (string) Str::uuid(), 'claim_id' => $claimId,
            'company_id' => $companyId, 'hall_id' => $hallId,
            'event_date' => $date, 'program' => $program, 'action' => $action,
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
                'reason' => $reason, 'hallId' => $hallId,
                'eventDate' => $date, 'program' => $program,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function occupy(
        User $actor, string $companyId, string $hallId,
        string $date, string $program, string $claimId, string $reason,
    ): void {
        $existing = DB::table('hall_slot_claims')->where('company_id', $companyId)
            ->where('hall_id', $hallId)->where('event_date', $date)
            ->where('program', $program)->lockForUpdate()->first();
        $before = null;
        if ($existing) {
            if ($existing->status !== 'hold') {
                throw new ConflictHttpException('The selected hall program is already confirmed.');
            }
            $expired = $existing->expires_at
                && Carbon::parse($existing->expires_at)->lessThanOrEqualTo(now());
            if (! $expired) {
                if ($existing->id !== $claimId || (int) $existing->created_by !== (int) $actor->getKey()) {
                    throw new ConflictHttpException('Another staff member has an active temporary hold.');
                }
                $before = ['status' => 'hold', 'expiresAt' => $existing->expires_at];
                DB::table('hall_slot_claims')->where('id', $existing->id)
                    ->update(['status' => 'confirmed', 'expires_at' => null, 'updated_at' => now()]);
            } else {
                $expiredBefore = ['status' => 'hold', 'expiresAt' => $existing->expires_at];
                $this->claimEvent($actor, $companyId, $hallId, $date, $program,
                    $existing->id, 'hold_expired', $expiredBefore, null,
                    'Expired temporary hold cleared before reservation confirmation');
                DB::table('hall_slot_claims')->where('id', $existing->id)->delete();
                $existing = null;
            }
        }
        if (! $existing) {
            // The PostgreSQL hall_slot_exclusive_claim unique index is the
            // final defense even under an independent concurrent transaction.
            DB::table('hall_slot_claims')->insert([
                'id' => $claimId, 'company_id' => $companyId, 'hall_id' => $hallId,
                'event_date' => $date, 'program' => $program,
                'status' => 'confirmed', 'expires_at' => null,
                'created_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->claimEvent($actor, $companyId, $hallId, $date, $program, $claimId,
            'confirmed', $before, ['status' => 'confirmed', 'expiresAt' => null], $reason);
    }

    public function confirm(
        User $actor, string $companyId, string $draftId,
        string $reservationId, string $claimId, int $expectedDraftRevision, string $reason,
    ): array {
        $this->gate();
        if (! Str::isUuid($draftId) || ! Str::isUuid($reservationId)
            || ! Str::isUuid($claimId) || $expectedDraftRevision < 1) {
            throw new InvalidArgumentException('Valid proposal, reservation, claim and revision are required.');
        }
        $reason = $this->reason($reason);
        $proposal = DB::table('booking_drafts')->where('company_id', $companyId)
            ->where('id', $draftId)->first();
        if (! $proposal) {
            throw new AuthorizationException('Proposal is not in the current company.');
        }
        $this->authorizeHall($actor, $companyId, $proposal->hall_id);

        return DB::transaction(function () use (
            $actor, $companyId, $draftId, $reservationId, $claimId, $expectedDraftRevision, $reason
        ): array {
            $this->gate();
            $draft = DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $draftId)->first();
            if (! $draft) {
                throw new AuthorizationException('Proposal not found.');
            }
            $hall = $this->authorizeHall($actor, $companyId, $draft->hall_id, true);
            $draft = DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $draftId)->lockForUpdate()->first();
            if (! $draft || $draft->hall_id !== $hall->id) {
                throw new ConflictHttpException('Proposal hall changed; retry against current data.');
            }
            $existing = DB::table('venue_reservations')->where('company_id', $companyId)
                ->where('source_draft_id', $draftId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->id === $reservationId && $existing->claim_id === $claimId
                    && $existing->status === 'confirmed'
                    && (int) $existing->created_by === (int) $actor->getKey()
                    && DB::table('venue_reservation_events')
                        ->where('reservation_id', $reservationId)
                        ->where('revision', 1)->where('action', 'confirmed')
                        ->where('reason', $reason)->exists()) {
                    return $this->bookingSnapshot($existing);
                }
                throw new ConflictHttpException('This proposal already has a reservation.');
            }
            if ($draft->status !== 'under_review'
                || (int) $draft->revision !== $expectedDraftRevision
                || (int) $hall->program_rules_revision < 1
                || (int) $draft->requested_guests > (int) $hall->capacity
                || ! DB::table('customer_profiles')->where('company_id', $companyId)
                    ->where('id', $draft->customer_id)->where('is_active', true)->exists()) {
                throw new ConflictHttpException('Proposal review, hall setup, guests or customer changed.');
            }
            $this->occupy($actor, $companyId, $hall->id,
                $draft->event_date, $draft->program, $claimId, $reason);
            // Consume the reviewed draft atomically, so owner proposal counts
            // and unrelated approvals no longer treat it as awaiting a booking.
            $beforeDraft = [
                'status' => $draft->status, 'revision' => (int) $draft->revision,
                'hallId' => $draft->hall_id, 'date' => $draft->event_date,
                'program' => $draft->program,
            ];
            $afterDraft = array_merge($beforeDraft, [
                'status' => 'booked', 'revision' => (int) $draft->revision + 1,
            ]);
            DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $draftId)->update([
                    'status' => 'booked', 'revision' => (int) $draft->revision + 1,
                    'updated_at' => now(),
                ]);
            DB::table('booking_draft_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'booking_id' => $draftId, 'revision' => (int) $draft->revision + 1,
                'action' => 'booked',
                'before_values' => json_encode($beforeDraft, JSON_THROW_ON_ERROR),
                'after_values' => json_encode($afterDraft, JSON_THROW_ON_ERROR),
                'actor_id' => $actor->getKey(), 'reason' => $reason,
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(), 'action' => 'booking_draft.booked',
                'entity_type' => 'booking_draft', 'entity_id' => $draftId,
                'before_values' => json_encode($beforeDraft, JSON_THROW_ON_ERROR),
                'after_values' => json_encode($afterDraft, JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
            DB::table('venue_reservations')->insert([
                'id' => $reservationId, 'company_id' => $companyId,
                'source_draft_id' => $draftId, 'customer_id' => $draft->customer_id,
                'hall_id' => $hall->id, 'claim_id' => $claimId,
                'event_date' => $draft->event_date, 'program' => $draft->program,
                'guests' => $draft->requested_guests, 'status' => 'confirmed',
                'revision' => 1, 'created_by' => $actor->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $reservation = DB::table('venue_reservations')->where('id', $reservationId)->first();
            $result = $this->bookingSnapshot($reservation);
            $this->record($actor, $companyId, $reservationId,
                'confirmed', 1, null, $result, $reason);

            return $result;
        }, 3);
    }

    /** Initial rescheduling scope is the SAME hall, with date/program change. */
    public function reschedule(
        User $actor, string $companyId, string $reservationId,
        string $date, string $program, string $newClaimId, int $revision,
        string $reason, ?string $destinationHallId = null,
    ): array {
        $this->gate();
        if (! Str::isUuid($reservationId) || ! Str::isUuid($newClaimId)
            || ($destinationHallId !== null && ! Str::isUuid($destinationHallId))
            || ! in_array($program, ['morning', 'noon', 'evening'], true) || $revision < 1) {
            throw new InvalidArgumentException('Invalid reschedule request.');
        }
        $this->date($date);
        $reason = $this->reason($reason);
        $initial = DB::table('venue_reservations')->where('company_id', $companyId)
            ->where('id', $reservationId)->first();
        if (! $initial) {
            throw new AuthorizationException('Reservation is not accessible.');
        }
        $destinationHallId ??= $initial->hall_id;
        $this->authorizeHall($actor, $companyId, $initial->hall_id);
        $this->authorizeHall($actor, $companyId, $destinationHallId);
        return DB::transaction(function () use ($actor, $companyId, $reservationId,
            $date, $program, $newClaimId, $revision, $reason, $destinationHallId): array {
            $this->gate();
            $initial = DB::table('venue_reservations')->where('company_id', $companyId)
                ->where('id', $reservationId)->first();
            if (! $initial) {
                throw new AuthorizationException('Reservation is not accessible.');
            }
            // Lock both source and target hall records in a stable global
            // UUID order. Different concurrent transfers cannot invert locks.
            $hallIds = array_values(array_unique([$initial->hall_id, $destinationHallId]));
            sort($hallIds, SORT_STRING);
            $lockedHalls = [];
            foreach ($hallIds as $id) {
                $lockedHalls[$id] = $this->authorizeHall($actor, $companyId, $id, true);
            }
            $sourceHall = $lockedHalls[$initial->hall_id];
            $targetHall = $lockedHalls[$destinationHallId];
            $saved = DB::table('venue_reservations')->where('company_id', $companyId)
                ->where('id', $reservationId)->lockForUpdate()->first();
            if (! $saved || $saved->hall_id !== $sourceHall->id || $saved->status !== 'confirmed'
                || (int) $saved->revision !== $revision
                || ($saved->hall_id === $destinationHallId
                    && $saved->event_date === $date && $saved->program === $program)
                || $saved->claim_id === $newClaimId) {
                throw new ConflictHttpException('Reservation revision or destination is invalid.');
            }
            if (DB::table('venue_event_operations')->where('company_id', $companyId)
                ->where('reservation_id', $reservationId)->exists()) {
                throw new ConflictHttpException('Started events cannot be transferred or rescheduled.');
            }
            if ((int) $targetHall->program_rules_revision < 1
                || (int) $saved->guests > (int) $targetHall->capacity) {
                throw new ConflictHttpException('Destination hall rules or capacity need review.');
            }
            $old = DB::table('hall_slot_claims')->where('company_id', $companyId)
                ->where('id', $saved->claim_id)->lockForUpdate()->first();
            if (! $old || $old->status !== 'confirmed'
                || $old->hall_id !== $sourceHall->id || $old->event_date !== $saved->event_date
                || $old->program !== $saved->program) {
                throw new ConflictHttpException('Physical source claim does not match reservation.');
            }
            $before = $this->bookingSnapshot($saved);
            // Occupy destination BEFORE releasing source, so a failed target
            // never leaves an existing reservation without its source slot.
            $this->occupy($actor, $companyId, $targetHall->id, $date, $program, $newClaimId, $reason);
            $this->claimEvent($actor, $companyId, $sourceHall->id, $old->event_date,
                $old->program, $old->id, 'cancelled',
                ['status' => 'confirmed'], null, 'Source slot released after audited reschedule');
            DB::table('hall_slot_claims')->where('id', $old->id)->delete();
            DB::table('venue_reservations')->where('id', $reservationId)->update([
                'hall_id' => $targetHall->id, 'claim_id' => $newClaimId,
                'event_date' => $date, 'program' => $program,
                'revision' => $revision + 1, 'updated_at' => now(),
            ]);
            $after = $this->bookingSnapshot(DB::table('venue_reservations')
                ->where('id', $reservationId)->first());
            $this->record($actor, $companyId, $reservationId,
                'rescheduled', $revision + 1, $before, $after, $reason);
            return $after;
        }, 3);
    }

    public function cancel(
        User $actor, string $companyId, string $reservationId, int $revision, string $reason,
    ): array {
        $this->gate();
        if (! Str::isUuid($reservationId) || $revision < 1) {
            throw new InvalidArgumentException('Invalid cancellation request.');
        }
        $reason = $this->reason($reason);
        $initial = DB::table('venue_reservations')->where('company_id', $companyId)
            ->where('id', $reservationId)->first();
        if (! $initial) {
            throw new AuthorizationException('Reservation is not accessible.');
        }
        $this->authorizeHall($actor, $companyId, $initial->hall_id);
        return DB::transaction(function () use ($actor, $companyId, $reservationId, $revision, $reason): array {
            $this->gate();
            $initial = DB::table('venue_reservations')->where('company_id', $companyId)
                ->where('id', $reservationId)->first();
            if (! $initial) {
                throw new AuthorizationException('Reservation is not accessible.');
            }
            $hall = $this->authorizeHall($actor, $companyId, $initial->hall_id, true);
            $saved = DB::table('venue_reservations')->where('company_id', $companyId)
                ->where('id', $reservationId)->lockForUpdate()->first();
            if (! $saved || $saved->hall_id !== $hall->id || $saved->status !== 'confirmed'
                || (int) $saved->revision !== $revision) {
                throw new ConflictHttpException('Reservation already changed or cancelled.');
            }
            if (DB::table('venue_event_operations')->where('company_id', $companyId)
                ->where('reservation_id', $reservationId)->exists()) {
                throw new ConflictHttpException('Started events cannot be silently cancelled.');
            }
            $claim = DB::table('hall_slot_claims')->where('company_id', $companyId)
                ->where('id', $saved->claim_id)->lockForUpdate()->first();
            if (! $claim || $claim->status !== 'confirmed'
                || $claim->hall_id !== $hall->id || $claim->event_date !== $saved->event_date
                || $claim->program !== $saved->program) {
                throw new ConflictHttpException('Reservation physical claim must be reconciled.');
            }
            $before = $this->bookingSnapshot($saved);
            $this->claimEvent($actor, $companyId, $hall->id, $claim->event_date,
                $claim->program, $claim->id, 'cancelled', ['status' => 'confirmed'], null, $reason);
            DB::table('hall_slot_claims')->where('id', $claim->id)->delete();
            DB::table('venue_reservations')->where('id', $reservationId)->update([
                'status' => 'cancelled', 'revision' => $revision + 1, 'updated_at' => now(),
            ]);
            $after = $this->bookingSnapshot(DB::table('venue_reservations')
                ->where('id', $reservationId)->first());
            $this->record($actor, $companyId, $reservationId,
                'cancelled', $revision + 1, $before, $after, $reason);
            return $after;
        }, 3);
    }

    /** Owner/admin-only private booking history by opaque company customer ID. */
    public function customerHistory(
        User $actor, string $companyId, string $customerId, int $limit = 50,
    ): array {
        if (! Str::isUuid($customerId) || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Invalid private customer booking history request.');
        }
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $this->access->canManageCustomerProfiles($actor, $companyId)) {
            throw new AuthorizationException('Only authorized customer staff can read history.');
        }
        if (! DB::table('customer_profiles')->where('company_id', $companyId)
            ->where('id', $customerId)->exists()) {
            throw new AuthorizationException('Customer is not in this company.');
        }
        return DB::table('venue_reservations')
            ->where('company_id', $companyId)->where('customer_id', $customerId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($row) => $this->bookingSnapshot($row))->all();
    }

    /** No names, email, phone or private financial amounts in staff projection. */
    public function list(User $actor, string $companyId, int $limit = 30): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Invalid reservation list limit.');
        }
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()) {
            throw new AuthorizationException('Verified staff session required.');
        }
        $rows = DB::table('venue_reservations')->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $out = [];
        foreach ($rows as $row) {
            try {
                $this->authorizeHall($actor, $companyId, $row->hall_id);
            } catch (AuthorizationException) {
                continue;
            }
            $out[] = $this->bookingSnapshot($row);
        }
        return $out;
    }
}
