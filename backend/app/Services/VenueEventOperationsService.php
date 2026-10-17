<?php

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Actual, manually observed confirmed-event start and completion.
 * Keeps immutable evidence; NEVER extends an exclusive slot or bills overtime.
 */
final class VenueEventOperationsService
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly HallOvertimePreviewService $overtime,
    ) {}

    private function gate(): void
    {
        if (config('event_controls.recording_enabled') !== true) {
            throw new ConflictHttpException('Actual event recording requires separate operator release approval.');
        }
    }

    private function authorizeHall(User $actor, string $companyId, string $hallId, bool $lock = false): Hall
    {
        $q = Hall::query()->where('company_id', $companyId)
            ->where('id', $hallId)->where('is_active', true);
        $hall = $lock ? $q->lockForUpdate()->first() : $q->first();
        $member = CompanyMembership::query()->where('company_id', $companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin', 'manager', 'reception', 'operations'])
            ->whereHas('company', fn ($c) => $c->where('is_active', true))->first();
        if (! $hall || ! $member || ! Auth::check()
            || (int) Auth::id() !== (int) $actor->getKey()
            || ! $this->access->canViewHall($actor, $hall)) {
            throw new AuthorizationException('Current staff has no access to this event hall.');
        }
        return $hall;
    }

    private function clock(string $value): int
    {
        if (! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $value)) {
            throw new InvalidArgumentException('Use the observed local 24-hour HH:MM clock.');
        }
        return (int) substr($value, 0, 2) * 60 + (int) substr($value, 3, 2);
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
            || preg_match('/[<>@[:cntrl:]]|https?:\/\/|www\./iu', $reason)) {
            throw new InvalidArgumentException('A safe plain operational reason is required.');
        }
        return $reason;
    }

    private function snapshot(object $row): array
    {
        return [
            'id' => $row->id, 'reservationId' => $row->reservation_id,
            'status' => $row->status, 'start' => $row->started_clock,
            'end' => $row->ended_clock, 'revision' => (int) $row->revision,
            'overtime' => $row->overtime_snapshot === null ? null
                : json_decode($row->overtime_snapshot, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function record(User $actor, string $companyId, string $operationId,
        string $action, int $revision, ?array $before, array $after, string $reason): void
    {
        $encoded = json_encode($after, JSON_THROW_ON_ERROR);
        DB::table('venue_event_operation_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'operation_id' => $operationId, 'revision' => $revision,
            'action' => $action, 'before_values' => $before === null ? null
                : json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => $encoded, 'actor_id' => $actor->getKey(),
            'reason' => $reason,
        ]);
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId,
            'actor_id' => $actor->getKey(),
            'action' => 'event_operation.'.$action,
            'entity_type' => 'venue_event_operation', 'entity_id' => $operationId,
            'before_values' => $before === null ? null
                : json_encode($before, JSON_THROW_ON_ERROR),
            'after_values' => $encoded,
            'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }

    /** Read-only worklist excludes customer identity and financial details. */
    public function worklist(User $actor, string $companyId, int $limit = 30): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Invalid event worklist bound.');
        }
        $halls = Hall::query()->where('company_id', $companyId)->where('is_active', true)
            ->get()->filter(fn (Hall $h) => $this->isAllowed($actor, $companyId, $h))
            ->pluck('id')->all();
        if ($halls === []) {
            throw new AuthorizationException('No currently accessible event halls.');
        }
        return DB::table('venue_reservations as r')
            ->join('halls as h', fn ($join) => $join->on('h.id', '=', 'r.hall_id')
                ->on('h.company_id', '=', 'r.company_id'))
            ->leftJoin('venue_event_operations as o', fn ($join) => $join
                ->on('o.reservation_id', '=', 'r.id')->on('o.company_id', '=', 'r.company_id'))
            ->where('r.company_id', $companyId)->whereIn('r.hall_id', $halls)
            ->where('r.status', 'confirmed')
            ->orderByDesc('r.event_date')->orderByDesc('r.id')->limit($limit)
            ->get(['r.id', 'r.event_date', 'r.program', 'h.name as hall_name',
                'o.id as operation_id', 'o.status as operation_status',
                'o.started_clock', 'o.ended_clock', 'o.revision',
                'o.overtime_snapshot'])
            ->map(fn ($row) => [
                'reservationId' => $row->id, 'date' => $row->event_date,
                'program' => $row->program, 'hallName' => $row->hall_name,
                'operationId' => $row->operation_id,
                'status' => $row->operation_status ?? 'not_started',
                'start' => $row->started_clock, 'end' => $row->ended_clock,
                'revision' => (int) ($row->revision ?? 0),
                'closeout' => $row->overtime_snapshot === null ? null
                    : json_decode($row->overtime_snapshot, true, 512, JSON_THROW_ON_ERROR),
            ])->all();
    }

    private function isAllowed(User $actor, string $companyId, Hall $hall): bool
    {
        try {
            $this->authorizeHall($actor, $companyId, $hall->id);
            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function lockedReservation(User $actor, string $companyId, string $reservationId): object
    {
        $initial = DB::table('venue_reservations')->where('company_id', $companyId)
            ->where('id', $reservationId)->first();
        if (! $initial) {
            throw new AuthorizationException('Unknown company reservation.');
        }
        // Lock order matches reschedule/cancel: hall, then reservation, then operation.
        $this->authorizeHall($actor, $companyId, $initial->hall_id, true);
        $saved = DB::table('venue_reservations')->where('company_id', $companyId)
            ->where('id', $reservationId)->lockForUpdate()->first();
        if (! $saved || $saved->hall_id !== $initial->hall_id
            || $saved->status !== 'confirmed'
            || ! DB::table('hall_slot_claims')->where('company_id', $companyId)
                ->where('id', $saved->claim_id)->where('status', 'confirmed')
                ->where('hall_id', $saved->hall_id)->where('event_date', $saved->event_date)
                ->where('program', $saved->program)->exists()) {
            throw new ConflictHttpException('Reservation or confirmed hall claim changed.');
        }
        return $saved;
    }

    public function start(User $actor, string $companyId, string $reservationId,
        string $operationId, string $clock, string $reason): array
    {
        $this->gate();
        if (! Str::isUuid($reservationId) || ! Str::isUuid($operationId)) {
            throw new InvalidArgumentException('Valid event references are required.');
        }
        $this->clock($clock);
        $reason = $this->reason($reason);
        return DB::transaction(function () use ($actor, $companyId, $reservationId, $operationId, $clock, $reason): array {
            $this->gate();
            $this->lockedReservation($actor, $companyId, $reservationId);
            if (DB::table('venue_event_operations')->where('company_id', $companyId)
                ->where('reservation_id', $reservationId)->exists()) {
                throw new ConflictHttpException('This event already has a recorded start.');
            }
            DB::table('venue_event_operations')->insert([
                'id' => $operationId, 'company_id' => $companyId,
                'reservation_id' => $reservationId, 'status' => 'in_progress',
                'started_clock' => $clock, 'revision' => 1,
                'started_by' => $actor->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $result = $this->snapshot(DB::table('venue_event_operations')
                ->where('id', $operationId)->first());
            $this->record($actor, $companyId, $operationId, 'started', 1, null, $result, $reason);
            return $result;
        }, 3);
    }

    public function finish(User $actor, string $companyId, string $reservationId,
        int $expectedRevision, string $clock, string $reason): array
    {
        $this->gate();
        if (! Str::isUuid($reservationId) || $expectedRevision < 1) {
            throw new InvalidArgumentException('Valid reservation and revision required.');
        }
        $ended = $this->clock($clock);
        $reason = $this->reason($reason);
        return DB::transaction(function () use ($actor, $companyId, $reservationId, $expectedRevision, $clock, $ended, $reason): array {
            $this->gate();
            $reservation = $this->lockedReservation($actor, $companyId, $reservationId);
            $operation = DB::table('venue_event_operations')
                ->where('company_id', $companyId)->where('reservation_id', $reservationId)
                ->lockForUpdate()->first();
            if (! $operation || $operation->status !== 'in_progress'
                || (int) $operation->revision !== $expectedRevision
                || $ended < $this->clock($operation->started_clock)) {
                throw new ConflictHttpException('Actual event clock or revision changed.');
            }
            // Frozen OBSERVATION of approved CURRENT rules. This is NOT a
            // contract adjustment, bill, or permission to exceed turnover.
            $preview = $this->overtime->preview($actor, $companyId,
                $reservation->hall_id, $reservation->program, $clock);

            // Lock approved original-hall preparation before sealing closure.
            // A planning task is not actual delivery: preserve the distinction.
            $reviewedIds = DB::table('operations_plan_tasks')
                ->where('company_id', $companyId)
                ->where('booking_id', $reservation->source_draft_id)
                ->where('hall_id', $reservation->hall_id)->where('status', 'reviewed')
                ->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $performedIds = $reviewedIds === [] ? [] :
                DB::table('venue_event_task_fulfillments')
                    ->where('company_id', $companyId)
                    ->where('reservation_id', $reservationId)
                    ->whereIn('planning_task_id', $reviewedIds)
                    ->pluck('planning_task_id')->all();
            $unperformed = array_values(array_diff($reviewedIds, $performedIds));
            $preview['taskCloseout'] = [
                'reviewedCount' => count($reviewedIds),
                'performedCount' => count($performedIds),
                'unperformedCount' => count($unperformed),
                'unperformedTaskIds' => $unperformed,
                'allReviewedTasksPerformed' => $unperformed === [],
            ];
            $before = $this->snapshot($operation);
            DB::table('venue_event_operations')->where('id', $operation->id)
                ->update(['status' => 'completed', 'ended_clock' => $clock,
                    'overtime_snapshot' => json_encode($preview, JSON_THROW_ON_ERROR),
                    'completed_by' => $actor->getKey(),
                    'revision' => $expectedRevision + 1, 'updated_at' => now()]);
            $after = $this->snapshot(DB::table('venue_event_operations')
                ->where('id', $operation->id)->first());
            $this->record($actor, $companyId, $operation->id,
                'completed', $expectedRevision + 1, $before, $after, $reason);
            return $after;
        }, 3);
    }
    /** Reviewed, same-hall pre-event tasks and distinct actual fulfilment evidence. */
    public function checklist(User $actor, string $companyId, string $reservationId): array
    {
        if (! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('Valid confirmed reservation required.');
        }
        $reservation = DB::table('venue_reservations')->where('company_id', $companyId)
            ->where('id', $reservationId)->first();
        if (! $reservation || $reservation->status !== 'confirmed') {
            throw new AuthorizationException('Confirmed reservation is not accessible.');
        }
        $this->authorizeHall($actor, $companyId, $reservation->hall_id);
        return DB::table('operations_plan_tasks as t')
            ->leftJoin('venue_event_task_fulfillments as f', fn ($join) => $join
                ->on('f.company_id', '=', 't.company_id')
                ->on('f.planning_task_id', '=', 't.id')
                ->where('f.reservation_id', '=', $reservationId))
            ->where('t.company_id', $companyId)
            ->where('t.booking_id', $reservation->source_draft_id)
            ->where('t.hall_id', $reservation->hall_id)
            ->orderBy('t.created_at')->orderBy('t.id')->limit(50)
            ->get(['t.id', 't.title', 't.status', 't.revision',
                'f.id as fulfillment_id', 'f.completed_by', 'f.created_at as fulfilled_at'])
            ->map(fn ($row) => [
                'taskId' => $row->id, 'title' => $row->title,
                'planningStatus' => $row->status,
                'planningRevision' => (int) $row->revision,
                'fulfilled' => $row->fulfillment_id !== null,
                'fulfillmentId' => $row->fulfillment_id,
                'fulfilledAt' => $row->fulfilled_at,
                'completedBy' => $row->completed_by,
            ])->all();
    }

    /** A witnessed service/equipment setup completion, never a paid add-on. */
    public function fulfillTask(
        User $actor, string $companyId, string $reservationId,
        string $taskId, string $fulfillmentId, string $reason,
    ): array {
        $this->gate();
        if (! Str::isUuid($reservationId) || ! Str::isUuid($taskId)
            || ! Str::isUuid($fulfillmentId)) {
            throw new InvalidArgumentException('Valid reservation, task and completion keys are required.');
        }
        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $actor, $companyId, $reservationId, $taskId, $fulfillmentId, $reason
        ): array {
            $this->gate();
            $reservation = $this->lockedReservation($actor, $companyId, $reservationId);
            $operation = DB::table('venue_event_operations')
                ->where('company_id', $companyId)->where('reservation_id', $reservationId)
                ->lockForUpdate()->first();
            if (! $operation || $operation->status !== 'in_progress') {
                throw new ConflictHttpException('Actual fulfilment can only be recorded while the event is in progress.');
            }
            $task = DB::table('operations_plan_tasks')->where('company_id', $companyId)
                ->where('id', $taskId)->lockForUpdate()->first();
            if (! $task || $task->booking_id !== $reservation->source_draft_id
                || $task->hall_id !== $reservation->hall_id || $task->status !== 'reviewed') {
                throw new AuthorizationException('Only reviewed tasks for this current booking and hall may be fulfilled.');
            }
            $existing = DB::table('venue_event_task_fulfillments')
                ->where('company_id', $companyId)
                ->where('reservation_id', $reservationId)
                ->where('planning_task_id', $taskId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->id === $fulfillmentId
                    && (int) $existing->completed_by === (int) $actor->getKey()
                    && $existing->reason === $reason) {
                    return ['id' => $existing->id, 'taskId' => $taskId,
                        'reservationId' => $reservationId, 'status' => 'fulfilled'];
                }
                throw new ConflictHttpException('Task fulfilment already recorded; retain original audit.');
            }

            $snapshot = [
                'id' => $fulfillmentId, 'taskId' => $taskId,
                'reservationId' => $reservationId, 'eventOperationId' => $operation->id,
                'title' => $task->title, 'planningRevision' => (int) $task->revision,
                'status' => 'fulfilled',
            ];
            DB::table('venue_event_task_fulfillments')->insert([
                'id' => $fulfillmentId, 'company_id' => $companyId,
                'reservation_id' => $reservationId, 'operation_id' => $operation->id,
                'planning_task_id' => $taskId,
                'planning_revision' => $task->revision,
                'title_snapshot' => $task->title, 'reason' => $reason,
                'completed_by' => $actor->getKey(),
            ]);
            DB::table('audit_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId,
                'actor_id' => $actor->getKey(),
                'action' => 'event_operation.task_fulfilled',
                'entity_type' => 'venue_event_task_fulfillment',
                'entity_id' => $fulfillmentId, 'before_values' => null,
                'after_values' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
            ]);
            return ['id' => $fulfillmentId, 'taskId' => $taskId,
                'reservationId' => $reservationId, 'status' => 'fulfilled'];
        }, 3);
    }

}
