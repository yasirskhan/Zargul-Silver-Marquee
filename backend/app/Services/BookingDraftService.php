<?php

namespace App\Services;

use App\Models\Hall;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Internal owner/admin-only preconfirmation proposal edits.
 * NEVER occupies a hall claim, prices a quote, confirms an event,
 * accepts public intake or returns customer personal information.
 */
final class BookingDraftService
{
    private const PROGRAMS = ['morning', 'noon', 'evening'];

    public function __construct(private readonly TenantAccess $access) {}

    public function create(
        User $actor, string $companyId, string $bookingId, string $hallId,
        string $customerId, string $eventDate, string $program,
        int $guests, string $reason,
    ): array {
        $this->authorizeCreate($actor, $companyId, $hallId);
        $this->validate($bookingId, $customerId, $eventDate, $program, $guests);
        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $actor, $companyId, $bookingId, $hallId, $customerId,
            $eventDate, $program, $guests, $reason
        ): array {
            $this->authorizeCreate($actor, $companyId, $hallId);
            $this->validateAssociations($actor, $companyId, $hallId, $customerId, $guests);
            $existing = DB::table('booking_drafts')->where('id', $bookingId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->company_id === $companyId
                    && $existing->hall_id === $hallId
                    && $existing->customer_id === $customerId
                    && $existing->event_date === $eventDate
                    && $existing->program === $program
                    && (int) $existing->requested_guests === $guests
                    && $existing->status === 'draft' && (int) $existing->revision === 1
                    && (int) $existing->created_by === (int) $actor->getKey()) {
                    return $this->result($bookingId, 1);
                }
                throw new ConflictHttpException('Booking draft request key already exists.');
            }

            DB::table('booking_drafts')->insert([
                'id' => $bookingId, 'company_id' => $companyId, 'hall_id' => $hallId,
                'customer_id' => $customerId, 'event_date' => $eventDate,
                'program' => $program, 'requested_guests' => $guests,
                'status' => 'draft', 'revision' => 1,
                'created_by' => $actor->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($actor, $companyId, $bookingId, 'created', 1, null,
                $this->snapshot($hallId, $customerId, $eventDate, $program, $guests, 1),
                $reason);

            return $this->result($bookingId, 1);
        }, 3);
    }

    public function update(
        User $actor, string $companyId, string $bookingId, string $hallId,
        string $customerId, string $eventDate, string $program,
        int $guests, int $expectedRevision, string $reason,
    ): array {
        $this->authorize($actor, $companyId);
        $this->validate($bookingId, $customerId, $eventDate, $program, $guests);
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('Expected draft revision is invalid.');
        }
        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $actor, $companyId, $bookingId, $hallId, $customerId,
            $eventDate, $program, $guests, $expectedRevision, $reason
        ): array {
            $this->authorize($actor, $companyId);
            $this->validateAssociations($actor, $companyId, $hallId, $customerId, $guests);
            $saved = DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $bookingId)->lockForUpdate()->first();
            if (! $saved) {
                throw new AuthorizationException('Booking draft is not accessible.');
            }
            if ($saved->status !== 'draft') {
                throw new ConflictHttpException('Only an editable draft may be updated.');
            }
            if ((int) $saved->revision !== $expectedRevision) {
                throw new ConflictHttpException('Booking draft changed; reload before saving.');
            }

            $before = $this->snapshot(
                $saved->hall_id, $saved->customer_id, $saved->event_date,
                $saved->program, (int) $saved->requested_guests, $expectedRevision
            );
            $next = $expectedRevision + 1;
            DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $bookingId)->update([
                    'hall_id' => $hallId, 'customer_id' => $customerId,
                    'event_date' => $eventDate, 'program' => $program,
                    'requested_guests' => $guests, 'revision' => $next, 'updated_at' => now(),
                ]);
            $after = $this->snapshot($hallId, $customerId, $eventDate, $program, $guests, $next);
            $this->record($actor, $companyId, $bookingId, 'updated', $next, $before, $after, $reason);

            return $this->result($bookingId, $next);
        }, 3);
    }

    public function current(User $actor, string $companyId, string $bookingId): ?array
    {
        $this->authorize($actor, $companyId);
        if (! Str::isUuid($bookingId)) {
            throw new InvalidArgumentException('Booking draft ID must be a UUID.');
        }
        $row = DB::table('booking_drafts')->where('company_id', $companyId)
            ->where('id', $bookingId)->first();
        if (! $row) {
            return null;
        }
        // No customer contact, private notes, pricing or protected hold details.
        return [
            'bookingId' => $row->id, 'hallId' => $row->hall_id,
            'customerId' => $row->customer_id, 'date' => $row->event_date,
            'program' => $row->program, 'guests' => (int) $row->requested_guests,
            'revision' => (int) $row->revision, 'status' => $row->status,
        ];
    }

    private function authorizeCreate(User $actor, string $companyId, string $hallId): void
    {
        $hall = Hall::query()->where('company_id', $companyId)
            ->where('id', $hallId)->where('is_active', true)->first();
        if (! $hall || ! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            || ! DB::table('users')->where('id', $actor->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()
            || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('Current assigned hall access is required to create booking drafts.');
        }
    }

    private function authorize(User $actor, string $companyId): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            || ! $this->access->canManageCustomerProfiles($actor, $companyId)) {
            throw new AuthorizationException('You cannot manage internal booking drafts.');
        }
    }

    private function validate(
        string $bookingId, string $customerId, string $eventDate,
        string $program, int $guests,
    ): void {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
        if (! Str::isUuid($bookingId) || ! Str::isUuid($customerId)
            || $date === false || $date->format('Y-m-d') !== $eventDate
            || ! in_array($program, self::PROGRAMS, true) || $guests < 1) {
            throw new InvalidArgumentException('Booking draft identifiers, date, program or guests are invalid.');
        }
    }

    private function validateAssociations(
        User $actor, string $companyId, string $hallId, string $customerId, int $guests,
    ): void {
        $hall = Hall::query()->where('company_id', $companyId)->where('id', $hallId)
            ->where('is_active', true)->first();
        if (! $hall || ! $this->access->canManageBookings($actor, $hall)) {
            throw new AuthorizationException('Booking draft hall is not accessible.');
        }
        if ((int) $hall->program_rules_revision < 1) {
            throw new ConflictHttpException('Configure all hall programs before drafting events.');
        }
        if ($guests > (int) $hall->capacity) {
            throw new InvalidArgumentException('Guest count exceeds hall capacity.');
        }
        if (! DB::table('customer_profiles')->where('company_id', $companyId)
            ->where('id', $customerId)->where('is_active', true)->exists()) {
            throw new AuthorizationException('Customer profile is not accessible.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>[:cntrl:]]/u', $reason)) {
            throw new InvalidArgumentException('A safe internal change reason is required.');
        }

        return $reason;
    }

    private function snapshot(
        string $hallId, string $customerId, string $date,
        string $program, int $guests, int $revision,
    ): array {
        return [
            'hallId' => $hallId, 'customerId' => $customerId,
            'eventDate' => $date, 'program' => $program, 'guests' => $guests,
            'revision' => $revision, 'status' => 'draft',
        ];
    }

    private function result(string $bookingId, int $revision): array
    {
        return ['bookingId' => $bookingId, 'revision' => $revision, 'status' => 'draft'];
    }

    private function record(
        User $actor, string $companyId, string $bookingId,
        string $action, int $revision, ?array $before, array $after, string $reason,
    ): void {
        DB::table('booking_draft_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'booking_id' => $bookingId, 'revision' => $revision,
            'action' => $action,
            'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_id' => $actor->getKey(), 'reason' => $reason,
        ]);
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(), 'action' => 'booking_draft.'.$action,
            'entity_type' => 'booking_draft', 'entity_id' => $bookingId,
            'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }
}
