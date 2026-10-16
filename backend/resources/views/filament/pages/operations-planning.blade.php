<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm font-semibold">Internal planning checklist only. These entries never confirm
            an event, reserve a hall, record that physical setup occurred, authorize overtime, or create a charge.</p>
        <label class="block space-y-1">
            <span class="text-sm font-medium">Company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        @if ($loadedCompanyId === $companyId)
            <section aria-label="Create proposed setup planning task" class="space-y-3 rounded-xl border p-4">
                <h2 class="text-lg font-semibold">Create internal planning task</h2>
                <label class="block space-y-1">
                    <span class="text-sm">Unconfirmed event proposal</span>
                    <select wire:model="bookingId" class="w-full rounded-lg border p-3 text-gray-900">
                        <option value="">Select permitted proposal</option>
                        @foreach ($this->proposalOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block space-y-1"><span class="text-sm">Task description (no contact information)</span>
                    <input type="text" maxlength="120" wire:model="taskTitle" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                <label class="block space-y-1"><span class="text-sm">Planning reason</span>
                    <textarea wire:model="reason" rows="2" maxlength="500" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                </label>
                @error('reason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="button" wire:click="createTask" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2 font-semibold">
                    Save nonbinding planning task
                </button>
            </section>
            <section aria-label="Authorized internal planning tasks" class="space-y-3">
                <h2 class="text-lg font-semibold">Planning checklist</h2>
                <label class="block space-y-1">
                    <span class="text-sm">Planning status</span>
                    <select wire:model.live="statusFilter" class="w-full rounded-lg border p-3 text-gray-900">
                        <option value="all">All planning tasks</option>
                        <option value="planned">Awaiting review</option>
                        <option value="reviewed">Reviewed planning tasks</option>
                    </select>
                    @error('statusFilter') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                </label>
                <label class="block space-y-1">
                    <span class="text-sm">Review/change reason (required for each transition)</span>
                    <textarea wire:model="reviewReason" rows="2" maxlength="500" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                </label>
                @error('reviewReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @forelse ($tasks as $task)
                    <article wire:key="plan-{{ $task['id'] }}" class="space-y-2 rounded-xl border p-4 text-sm">
                        <div class="flex flex-wrap justify-between gap-2">
                            <strong>{{ $task['title'] }}</strong>
                            <span>Planning status: {{ $task['status'] }} | revision {{ $task['revision'] }}</span>
                        </div>
                        <p class="break-all text-xs">Proposal {{ $task['bookingId'] }}</p>
                        <button type="button" wire:click="showHistory('{{ $task['id'] }}')"
                            class="rounded-lg border px-3 py-2">View immutable review history</button>
                        @if ($task['status'] === 'planned')
                            <button type="button" wire:click="markReviewed('{{ $task['id'] }}', true)"
                                class="rounded-lg border px-3 py-2">Mark plan reviewed</button>
                        @else
                            <button type="button" wire:click="markReviewed('{{ $task['id'] }}', false)"
                                class="rounded-lg border px-3 py-2">Reopen planning review</button>
                        @endif
                    </article>
                @empty
                    <p class="rounded-lg border p-4 text-sm">No internal planning tasks in your assigned halls.</p>
                @endforelse
                @if ($historyTaskId)
                    <section aria-label="Immutable planning review history" class="space-y-2 rounded-xl border p-4 text-sm">
                        <h3 class="font-semibold">Planning review history</h3>
                        <p class="break-all text-xs">Task {{ $historyTaskId }}</p>
                        @foreach ($historyEvents as $event)
                            <div wire:key="ops-history-{{ $historyTaskId }}-{{ $event['revision'] }}" class="border-t py-2">
                                <p>Revision {{ $event['revision'] }}: {{ $event['action'] }} by staff #{{ $event['actorId'] }}</p>
                                <p>{{ $event['at'] }} | {{ $event['status'] }}</p>
                                <p class="break-words">{{ $event['reason'] }}</p>
                            </div>
                        @endforeach
                    </section>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
