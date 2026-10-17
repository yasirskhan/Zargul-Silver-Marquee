<x-filament-panels::page>
    <div class="space-y-5">
        <p class="text-sm">Record actual observed start and finish for confirmed internal events.
            Recording is an immutable operating log, not permission to extend a hall session or an invoice adjustment.
            Enter the local hall wall-clock time (HH:MM) on the booked event date.</p>
        <label class="block space-y-1">
            <span>Operating company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        @if (! $this->recordingEnabled())
            <p role="status" class="rounded-lg border p-3 font-semibold">
                Actual event recording is disabled until separate operator release approval.
                Staff may review permitted confirmed bookings, but may not record actual times.
            </p>
        @endif
        @if ($loadedCompanyId === $companyId)
            <section class="space-y-3" aria-label="Current hall event worklist">
                <h2 class="font-semibold">Confirmed events in permitted halls</h2>
                <button type="button" wire:click="refresh" class="rounded-lg border px-3 py-2">Refresh assigned event list</button>
                <div class="grid gap-3 md:grid-cols-2">
                    @forelse ($rows as $row)
                        <article wire:key="event-{{ $row['reservationId'] }}" class="space-y-2 rounded-xl border p-3 text-sm">
                            <strong>{{ $row['hallName'] }} · {{ $row['date'] }} · {{ ucfirst($row['program']) }}</strong>
                            <p>Status: {{ str_replace('_', ' ', $row['status']) }}
                                @if ($row['start'] !== null) · observed start {{ $row['start'] }} @endif
                                @if ($row['end'] !== null) · observed finish {{ $row['end'] }} @endif
                            </p>
                            <p class="break-all text-xs">Confirmed booking {{ $row['reservationId'] }}</p>
                            <button type="button" wire:click="selectReservation('{{ $row['reservationId'] }}')"
                                class="rounded-lg border px-3 py-2 underline">Record or review event</button>
                        </article>
                    @empty
                        <p class="text-sm">No confirmed events in currently assigned halls.</p>
                    @endforelse
                </div>
            </section>
            @if ($selectedReservationId !== null && ($selected = $this->selectedRow()))
                <section aria-label="Recorded actual event time" class="space-y-3 rounded-xl border p-4">
                    <h2 class="font-semibold">Actual event record</h2>
                    <p>{{ $selected['hallName'] }} · {{ $selected['date'] }} · {{ ucfirst($selected['program']) }}
                        · {{ str_replace('_', ' ', $selected['status']) }}</p>
                    @if (count($checklist) > 0)
                        <section aria-label="Actual service and setup fulfilment" class="space-y-2 rounded-lg border p-3">
                            <h3 class="font-semibold">Reviewed setup tasks for this confirmed hall</h3>
                            <p class="text-xs">A reviewed PLAN is not proof of service. Record witnessed actual fulfilment only while this event is in progress. Financial add-on charges require separate authorization.</p>
                            <label class="block space-y-1">
                                <span>Actual task completion reason / observation</span>
                                <textarea wire:model="taskReason" maxlength="500" rows="2"
                                    class="w-full rounded-lg border p-2 text-gray-900"></textarea>
                            </label>
                            @error('taskReason') <p role="alert">{{ $message }}</p> @enderror
                            @foreach ($checklist as $task)
                                <article wire:key="reviewed-task-{{ $task['taskId'] }}" class="rounded-lg border p-2 text-sm">
                                    <strong>{{ $task['title'] }}</strong>
                                    <p>Planning: {{ $task['planningStatus'] }} · actual: {{ $task['fulfilled'] ? 'performed' : 'not recorded' }}</p>
                                    @if ($task['fulfilled'])
                                        <p class="text-xs">Performed at {{ $task['fulfilledAt'] }} · staff #{{ $task['completedBy'] }}</p>
                                    @elseif ($selected['status'] === 'in_progress' && $task['planningStatus'] === 'reviewed')
                                        <button type="button" wire:click="fulfillSelectedTask('{{ $task['taskId'] }}')"
                                            wire:loading.attr="disabled" @disabled(! $this->recordingEnabled())
                                            class="rounded-lg border px-3 py-2 underline">Record task performed</button>
                                    @endif
                                </article>
                            @endforeach
                        </section>
                    @endif
                    @if ($selected['status'] !== 'completed')
                        <label class="block space-y-1">
                            <span>{{ $selected['status'] === 'not_started' ? 'Observed event start' : 'Observed event finish' }} (24-hour HH:MM)</span>
                            @if ($selected['status'] === 'not_started')
                                <input type="time" wire:model="startClock" class="w-full rounded-lg border p-3 text-gray-900">
                            @else
                                <input type="time" wire:model="endClock" class="w-full rounded-lg border p-3 text-gray-900">
                            @endif
                        </label>
                        <label class="block space-y-1">
                            <span>Operational observation/reason (never customer personal data)</span>
                            <textarea wire:model="reason" maxlength="500" rows="2" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                        </label>
                        @error('startClock') <p role="alert">{{ $message }}</p> @enderror
                        @error('endClock') <p role="alert">{{ $message }}</p> @enderror
                        @error('reason') <p role="alert">{{ $message }}</p> @enderror
                        @if ($selected['status'] === 'not_started')
                            <button type="button" wire:click="startEvent" wire:loading.attr="disabled"
                                @disabled(! $this->recordingEnabled())
                                class="rounded-lg border px-4 py-2 disabled:opacity-50">Record actual event start</button>
                        @else
                            <button type="button" wire:click="finishEvent" wire:loading.attr="disabled"
                                @disabled(! $this->recordingEnabled())
                                class="rounded-lg border px-4 py-2 disabled:opacity-50">Complete actual event record</button>
                        @endif
                    @else
                        <p class="text-sm">Event completed and sealed in immutable audit history.
                            Overtime, if observed, remains a nonbinding calculation and cannot authorize a late hall extension.</p>
                        @if (is_array($selected['closeout'] ?? null))
                            <section class="space-y-2 rounded-lg border p-3" aria-label="Frozen actual event closeout">
                                <h3 class="font-semibold">Frozen operational closeout</h3>
                                <p>Observed overtime: {{ $selected['closeout']['overtimeMinutes'] }} minutes;
                                    beyond grace: {{ $selected['closeout']['chargeableMinutes'] }} minutes
                                    ({{ $selected['closeout']['startedIntervals'] }} started billing intervals).
                                    Nonbinding estimate: {{ $selected['closeout']['currency'] }}
                                    {{ $selected['closeout']['projectedAmountMinor'] }} minor units.</p>
                                @if ($selected['closeout']['conflictsWithTurnover'])
                                    <p role="status" class="font-semibold">Actual finish exceeded the protected next-program turnover limit.
                                        This record does NOT authorize a hall extension.</p>
                                @endif
                                <p>Reviewed setup tasks: {{ $selected['closeout']['taskCloseout']['reviewedCount'] }};
                                    witnessed performed: {{ $selected['closeout']['taskCloseout']['performedCount'] }};
                                    not witnessed: {{ $selected['closeout']['taskCloseout']['unperformedCount'] }}.</p>
                                @if (! $selected['closeout']['taskCloseout']['allReviewedTasksPerformed'])
                                    <p role="status" class="font-semibold">Outstanding setup fulfilment remains unresolved in this immutable closeout.</p>
                                @endif
                            </section>
                        @endif
                    @endif
                </section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
