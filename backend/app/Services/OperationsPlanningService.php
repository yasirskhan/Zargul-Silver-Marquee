<?php

namespace App\Services;

use App\Models\User;
use App\Policies\OperationsPlanningPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * ONLY an internal setup PLANNING checklist on an unconfirmed proposal.
 * This never records physical setup, staff attendance, hall booking, overtime,
 * customer messages, invoices, charges, or completion of an actual event.
 */
final class OperationsPlanningService
{
    public function __construct(private readonly OperationsPlanningPolicy $policy) {}

    private function safeText(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) < 3 || mb_strlen($value) > $max
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $value)) {
            throw new InvalidArgumentException('Use plain operational text without private contact details.');
        }
        return $value;
    }

    private function authorize(User $actor, string $companyId, string $hallId): void
    {
        if (! $this->policy->plan($actor, $companyId, $hallId)) {
            throw new AuthorizationException('Assigned hall planning access is required.');
        }
    }

    /** Recheck source data before planning can be created or reviewed. */
    private function assertCurrentProposalReady(string $companyId, object $booking): void
    {
        $hall = DB::table('halls')->where('company_id', $companyId)
            ->where('id', $booking->hall_id)->where('is_active', true)->first();
        if (! $hall || (int) $hall->program_rules_revision < 1
            || (int) $booking->requested_guests > (int) $hall->capacity
            || ! DB::table('hall_program_rule_revisions')
                ->where('company_id', $companyId)->where('hall_id', $booking->hall_id)
                ->where('revision_number', $hall->program_rules_revision)->exists()
            || ! DB::table('customer_profiles')->where('company_id', $companyId)
                ->where('id', $booking->customer_id)->where('is_active', true)->exists()) {
            throw new ConflictHttpException('Planning requires a valid active proposal, hall capacity, approved program rules and customer.');
        }
    }

    private function snapshot(object $row): array
    {
        return ['id' => $row->id, 'bookingId' => $row->booking_id,
            'hallId' => $row->hall_id, 'title' => $row->title,
            'status' => $row->status, 'revision' => (int) $row->revision];
    }

    public function tasks(User $actor, string $companyId, int $limit = 30, string $status = 'all'): array
    {
        $member = $this->policy->membership($actor, $companyId);
        if (! $member || $limit < 1 || $limit > 50) {
            throw new AuthorizationException('Company operations planning is inaccessible.');
        }
        if (! in_array($status, ['all', 'planned', 'reviewed'], true)) {
            throw new InvalidArgumentException('Invalid planning status filter.');
        }
        $q = DB::table('operations_plan_tasks')->where('company_id', $companyId);
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if (in_array($member->role, ['manager', 'operations'], true)) {
            $halls = DB::table('hall_assignments')
                ->where('company_id', $companyId)->where('membership_id', $member->id)
                ->pluck('hall_id')->all();
            $q->whereIn('hall_id', $halls);
        }
        return $q->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'booking_id', 'hall_id', 'title', 'status', 'revision'])
            ->map(fn ($row) => $this->snapshot($row))->all();
    }

    /** Read-only chronological provenance, with fresh company and hall policy. */
    public function history(User $actor, string $companyId, string $taskId, int $limit = 30): array
    {
        if (! Str::isUuid($taskId) || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Invalid planning history request.');
        }
        $task = DB::table('operations_plan_tasks')->where('company_id', $companyId)
            ->where('id', $taskId)->first(['hall_id']);
        if (! $task) {
            throw new AuthorizationException('Planning history is unavailable.');
        }
        $this->authorize($actor, $companyId, $task->hall_id);
        return DB::table('operations_plan_events')->where('company_id', $companyId)
            ->where('task_id', $taskId)->orderByDesc('revision')->limit($limit)
            ->get(['revision', 'action', 'status', 'actor_id', 'reason', 'created_at'])
            ->map(fn ($row) => [
                'revision' => (int) $row->revision, 'action' => $row->action,
                'status' => $row->status, 'actorId' => (int) $row->actor_id,
                'reason' => $row->reason, 'at' => (string) $row->created_at,
            ])->all();
    }

    public function create(
        User $actor, string $companyId, string $taskId, string $bookingId,
        string $title, string $reason,
    ): array {
        if (! Str::isUuid($taskId) || ! Str::isUuid($bookingId)) {
            throw new InvalidArgumentException('A valid task and proposal UUID are required.');
        }
        $title = $this->safeText($title, 120);
        $reason = $this->safeText($reason, 500);
        return DB::transaction(function () use (
            $actor, $companyId, $taskId, $bookingId, $title, $reason
        ): array {
            $booking = DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $bookingId)->lockForUpdate()->first();
            if (! $booking) {
                throw new AuthorizationException('Proposal unavailable.');
            }
            $this->authorize($actor, $companyId, $booking->hall_id);
            $existing = DB::table('operations_plan_tasks')->where('id', $taskId)->first();
            if ($existing) {
                if ($existing->company_id === $companyId
                    && $existing->booking_id === $bookingId
                    && (int) $existing->created_by === (int) $actor->getKey()
                    && $existing->title === $title && $existing->creation_reason === $reason) {
                    return $this->snapshot($existing);
                }
                throw new ConflictHttpException('This task request UUID belongs to another payload.');
            }
            if (! in_array($booking->status, ['draft', 'under_review'], true)) {
                throw new ConflictHttpException('Planning for a withdrawn proposal is prohibited.');
            }
            $this->assertCurrentProposalReady($companyId, $booking);
            DB::table('operations_plan_tasks')->insert([
                'id' => $taskId, 'company_id' => $companyId,
                'booking_id' => $bookingId, 'hall_id' => $booking->hall_id,
                'title' => $title, 'status' => 'planned', 'revision' => 1,
                'creation_reason' => $reason, 'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $after = ['id' => $taskId, 'bookingId' => $bookingId,
                'hallId' => $booking->hall_id, 'title' => $title,
                'status' => 'planned', 'revision' => 1];
            $this->record($actor, $companyId, $taskId, 1, 'created', 'planned',
                $reason, null, $after);
            return $after;
        }, 3);
    }

    public function setReviewed(
        User $actor, string $companyId, string $taskId,
        int $expectedRevision, bool $reviewed, string $reason,
    ): array {
        if (! Str::isUuid($taskId) || $expectedRevision < 1) {
            throw new InvalidArgumentException('A valid task UUID and revision are required.');
        }
        $reason = $this->safeText($reason, 500);
        return DB::transaction(function () use (
            $actor, $companyId, $taskId, $expectedRevision, $reviewed, $reason
        ): array {
            // Discover the immutable task/booking relationship first. Lock the
            // proposal BEFORE the task, matching create() and withdrawal lock order.
            // This avoids an inverted booking/task lock cycle under concurrent staff edits.
            $lookup = DB::table('operations_plan_tasks')->where('company_id', $companyId)
                ->where('id', $taskId)->first();
            if (! $lookup) {
                throw new AuthorizationException('Task is unavailable.');
            }
            $this->authorize($actor, $companyId, $lookup->hall_id);
            $booking = DB::table('booking_drafts')->where('company_id', $companyId)
                ->where('id', $lookup->booking_id)->lockForUpdate()->first();
            $saved = DB::table('operations_plan_tasks')->where('company_id', $companyId)
                ->where('id', $taskId)->lockForUpdate()->first();
            if (! $saved || $saved->booking_id !== $lookup->booking_id
                || $saved->hall_id !== $lookup->hall_id) {
                throw new ConflictHttpException('Planning relationship changed; reload.');
            }
            $this->authorize($actor, $companyId, $saved->hall_id);
            if (! $booking || ! in_array($booking->status, ['draft', 'under_review'], true)) {
                throw new ConflictHttpException('Withdrawn proposal cannot progress planning.');
            }
            $this->assertCurrentProposalReady($companyId, $booking);
            $newStatus = $reviewed ? 'reviewed' : 'planned';
            if ((int) $saved->revision !== $expectedRevision
                || $saved->status === $newStatus) {
                throw new ConflictHttpException('Task changed; reload before changing planning status.');
            }
            $before = $this->snapshot($saved);
            $next = $expectedRevision + 1;
            DB::table('operations_plan_tasks')->where('company_id', $companyId)
                ->where('id', $taskId)->update([
                    'status' => $newStatus, 'revision' => $next,
                    'updated_by' => $actor->getKey(), 'updated_at' => now(),
                ]);
            $after = array_merge($before, ['status' => $newStatus, 'revision' => $next]);
            $this->record($actor, $companyId, $taskId, $next,
                $reviewed ? 'reviewed' : 'reopened', $newStatus, $reason,
                $before, $after);
            return $after;
        }, 3);
    }

    private function record(
        User $actor, string $companyId, string $taskId, int $revision,
        string $action, string $status, string $reason,
        ?array $before, array $after,
    ): void {
        DB::table('operations_plan_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'task_id' => $taskId, 'revision' => $revision,
            'action' => $action, 'status' => $status,
            'actor_id' => $actor->getKey(), 'reason' => $reason,
        ]);
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(), 'action' => 'operations.plan_'.$action,
            'entity_type' => 'operations_plan_task', 'entity_id' => $taskId,
            'before_values' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }
}
