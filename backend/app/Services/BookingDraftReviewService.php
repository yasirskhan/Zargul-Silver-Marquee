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

/**
 * Internal preconfirmation review workflow. No manager approval, slot claim,
 * payment, quotation or booking confirmation can happen from this service.
 */
final class BookingDraftReviewService
{
    public function __construct(private readonly TenantAccess $access) {}

    public function submit(
        User $actor, string $companyId, string $bookingId,
        int $expectedRevision, string $reason,
    ): array {
        return $this->transition($actor, $companyId, $bookingId,
            $expectedRevision, $reason, 'submitted');
    }

    public function withdraw(
        User $actor, string $companyId, string $bookingId,
        int $expectedRevision, string $reason,
    ): array {
        return $this->transition($actor, $companyId, $bookingId,
            $expectedRevision, $reason, 'withdrawn');
    }

    private function transition(
        User $actor, string $companyId, string $bookingId,
        int $expectedRevision, string $reason, string $action,
    ): array {
        $this->authorize($actor, $companyId);
        if (! Str::isUuid($bookingId) || $expectedRevision < 1
            || ! in_array($action, ['submitted', 'withdrawn'], true)) {
            throw new InvalidArgumentException('Invalid proposal transition request.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>[:cntrl:]]/u', $reason)) {
            throw new InvalidArgumentException('A safe reason is required for proposal review changes.');
        }

        return DB::transaction(function () use (
            $actor, $companyId, $bookingId, $expectedRevision, $reason, $action
        ): array {
            $this->authorize($actor, $companyId);
            $saved = DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $bookingId)->lockForUpdate()->first();
            if (! $saved) {
                throw new AuthorizationException('This proposal is not accessible.');
            }
            if ((int) $saved->revision !== $expectedRevision) {
                throw new ConflictHttpException('Proposal changed; reload before requesting review.');
            }
            if ($action === 'submitted' && $saved->status !== 'draft') {
                throw new ConflictHttpException('Only a draft proposal may be sent for review.');
            }
            if ($action === 'withdrawn'
                && ! in_array($saved->status, ['draft', 'under_review'], true)) {
                throw new ConflictHttpException('This proposal can no longer be withdrawn.');
            }

            // A review request is NOT a claim, but must not promote invalid
            // proposal parameters in an obsolete/deactivated hall.
            if ($action === 'submitted') {
                $hall = Hall::query()->where('company_id', $companyId)
                    ->where('id', $saved->hall_id)->where('is_active', true)->first();
                if (! $hall || ! $this->access->canConfigureHall($actor, $hall)
                    || (int) $hall->program_rules_revision < 1
                    || (int) $saved->requested_guests > (int) $hall->capacity
                    || ! DB::table('customer_profiles')->where('company_id', $companyId)
                        ->where('id', $saved->customer_id)->where('is_active', true)->exists()) {
                    throw new ConflictHttpException('Proposal hall, capacity or customer needs review before submitting.');
                }
            }

            $before = $this->snapshot($saved);
            $next = $expectedRevision + 1;
            $status = $action === 'submitted' ? 'under_review' : 'withdrawn';
            DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $bookingId)->update([
                    'status' => $status, 'revision' => $next, 'updated_at' => now(),
                ]);
            $after = array_merge($before, ['status' => $status, 'revision' => $next]);
            DB::table('booking_draft_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'booking_id' => $bookingId, 'revision' => $next, 'action' => $action,
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

            return ['bookingId' => $bookingId, 'revision' => $next, 'status' => $status];
        }, 3);
    }

    private function authorize(User $actor, string $companyId): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $actor->getKey()
            || ! $actor->hasVerifiedEmail()
            || ! $this->access->canManageCustomerProfiles($actor, $companyId)) {
            throw new AuthorizationException('You cannot review internal proposals.');
        }
    }

    /** Redacted history, never contact name, email or phone. */
    private function snapshot(object $row): array
    {
        return [
            'hallId' => $row->hall_id,
            'customerId' => $row->customer_id,
            'eventDate' => $row->event_date,
            'program' => $row->program,
            'guests' => (int) $row->requested_guests,
            'status' => $row->status,
            'revision' => (int) $row->revision,
        ];
    }
}
