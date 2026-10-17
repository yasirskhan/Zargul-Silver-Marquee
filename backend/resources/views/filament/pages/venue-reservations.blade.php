<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm">Private venue reservations. A confirmed reservation occupies exactly one
            hall/date/program slot. This panel does not create invoices, collect payments or submit public enquiries.</p>
        <label class="block space-y-1">
            <span class="text-sm font-medium">Operating company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        @if (! $this->enabled())
            <p role="status" class="rounded-lg border p-3 font-semibold">
                Live reservation confirmation is disabled until venue release approval.
                Internal proposals and calendar remain available separately.
            </p>
        @endif
        @if ($loadedCompanyId === $companyId)
            <section class="space-y-3 rounded-xl border p-4" aria-label="Confirm reviewed internal proposal">
                <h2 class="text-lg font-semibold">Confirm reviewed hall reservation</h2>
                <p class="text-xs">Only under-review proposals for halls currently assigned to you appear here.
                    Customer contacts and financial amounts are not displayed.</p>
                <label class="block space-y-1">
                    <span class="text-sm">Reviewed event proposal</span>
                    <select wire:model="draftId" class="w-full rounded-lg border p-3 text-gray-900">
                        <option value="">Select permitted reviewed proposal</option>
                        @foreach ($this->proposalOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block space-y-1">
                    <span class="text-sm">Confirmation / change reason</span>
                    <textarea wire:model="reason" rows="2" maxlength="500" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                </label>
                @error('draftId') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @error('reason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="button" wire:click="confirmSelected" wire:loading.attr="disabled" @disabled(! $this->enabled())
                    class="rounded-lg border px-4 py-2 font-semibold disabled:opacity-50">
                    Confirm hall/date/program reservation
                </button>
                <a class="inline-flex underline" href="{{ \App\Filament\Pages\HallCalendar::getUrl(['company' => $companyId]) }}">Open hall calendar</a>
            </section>

            <section class="space-y-3" aria-label="Current authorized venue reservations">
                <h2 class="text-lg font-semibold">Reservations in permitted halls</h2>
                <div class="grid gap-3 md:grid-cols-2">
                    @forelse ($reservations as $row)
                        <article wire:key="reservation-{{ $row['id'] }}" class="space-y-2 rounded-xl border p-4 text-sm">
                            <strong>{{ $this->hallOptions()[$row['hallId']] ?? 'Hall' }} · {{ $row['date'] }} {{ ucfirst($row['program']) }}</strong>
                            <p>{{ $row['guests'] }} guests · {{ ucfirst($row['status']) }} · revision {{ $row['revision'] }}</p>
                            <p class="break-all text-xs">Reservation {{ $row['id'] }}</p>
                            @if ($this->mayViewCustomerHistory())
                                <button type="button" wire:click="showCustomerHistory('{{ $row['customerId'] }}')"
                                    class="rounded-lg border px-3 py-2 underline">Customer booking history</button>
                            @endif
                            @if ($row['status'] === 'confirmed')
                                <button type="button" wire:click="selectReservation('{{ $row['id'] }}')"
                                    class="rounded-lg border px-3 py-2 underline">Manage reservation</button>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm">No reservations in your permitted halls.</p>
                    @endforelse
                </div>
            </section>
            @if ($selectedCustomerId !== '' && $this->mayViewCustomerHistory())
                <section class="space-y-3 rounded-xl border p-4" aria-label="Private customer reservation history">
                    <h2 class="font-semibold">Customer booking history (internal reference only)</h2>
                    <p class="break-all text-xs">Customer reference: {{ $selectedCustomerId }}</p>
                    <button type="button" wire:click="clearCustomerHistory"
                        class="rounded-lg border px-3 py-2 text-sm">Close history</button>
                    @forelse ($customerReservations as $entry)
                        <p class="rounded-lg border p-3 text-sm">
                            {{ $entry['date'] }} {{ ucfirst($entry['program']) }}
                            · {{ $entry['guests'] }} guests · {{ ucfirst($entry['status']) }}
                            · reservation {{ $entry['id'] }}
                        </p>
                    @empty
                        <p class="text-sm">No reservation history for this customer.</p>
                    @endforelse
                </section>
            @endif
            @if ($selectedReservationId !== null)
                <section class="space-y-3 rounded-xl border p-4" aria-label="Change confirmed hall reservation">
                    <h2 class="font-semibold">Manage selected reservation</h2>
                    <p class="break-all text-xs">{{ $selectedReservationId }} · expected revision {{ $selectedRevision }}</p>
                    <p class="text-xs">Choose a permitted destination hall, date and program.
                        The old slot is only released after the new slot is secured atomically.</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="space-y-1"><span class="text-sm">Destination hall</span>
                            <select wire:model="newHallId" class="w-full rounded-lg border p-3 text-gray-900">
                                @foreach ($this->hallOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="space-y-1"><span class="text-sm">New date</span>
                            <input type="date" wire:model="newDate" class="w-full rounded-lg border p-3 text-gray-900">
                        </label>
                        <label class="space-y-1"><span class="text-sm">New program</span>
                            <select wire:model="newProgram" class="w-full rounded-lg border p-3 text-gray-900">
                                <option value="morning">Morning</option>
                                <option value="noon">Noon</option>
                                <option value="evening">Evening</option>
                            </select>
                        </label>
                    </div>
                    <label class="space-y-1"><span class="text-sm">Change or cancellation reason</span>
                        <textarea wire:model="reason" rows="2" maxlength="500" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                    </label>
                    @error('newHallId') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @error('newDate') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @error('newProgram') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-3">
                        <button type="button" wire:click="rescheduleSelected" wire:loading.attr="disabled"
                            @disabled(! $this->enabled()) class="rounded-lg border px-4 py-2 disabled:opacity-50">
                            Reschedule or transfer hall
                        </button>
                        <button type="button" wire:click="cancelSelected" wire:loading.attr="disabled"
                            @disabled(! $this->enabled()) class="rounded-lg border px-4 py-2 disabled:opacity-50">
                            Cancel and release hall slot
                        </button>
                    </div>
                </section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
