<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Internal, read-only hall occupancy. Three programs per date. An expired hold may still block
            the hall until the audited hold service clears it. This is not customer availability or a booking screen.
        </p>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <label class="block space-y-2">
                <span class="text-sm font-medium">Company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->companyOptions() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Hall</span>
                <select wire:model.live="hallId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->hallOptions() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Calendar view</span>
                <select wire:model.live="viewMode" class="w-full rounded-lg border p-3 text-gray-900">
                    <option value="day">Day</option>
                    <option value="week">Week</option>
                    <option value="month">Month</option>
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Date</span>
                <input type="date" wire:model="anchorDate" class="w-full rounded-lg border p-3 text-gray-900">
                @error('anchorDate') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>
        </div>
        <div class="flex flex-wrap items-center gap-3" aria-label="Calendar navigation">
            <button type="button" wire:click="stepPeriod(-1)" class="rounded-lg border px-4 py-2 text-sm">Previous</button>
            <button type="button" wire:click="jumpToToday" class="rounded-lg border px-4 py-2 text-sm">Today</button>
            <button type="button" wire:click="reloadCalendar" class="rounded-lg border px-4 py-2 text-sm">Go to date / refresh</button>
            <button type="button" wire:click="stepPeriod(1)" class="rounded-lg border px-4 py-2 text-sm">Next</button>
            @error('viewMode') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        @if ($loadedCompanyId === $companyId && $loadedHallId === $hallId && $projection !== [])
            <section class="space-y-4" aria-label="Hall occupancy projection">
                <h2 class="text-lg font-semibold">Hall occupancy: {{ $projection['fromDate'] }} to {{ $projection['toDate'] }}</h2>
                <p class="text-sm">{{ $projection['configured'] ? 'Hall programs configured.' : 'Programs unconfigured. No open slots.' }}
                    Last refreshed: {{ $projection['asOf'] }}.</p>
                <div class="flex flex-wrap gap-3 text-xs" aria-label="Occupancy status legend">
                    <span class="rounded-lg bg-emerald-100 px-3 py-2 text-emerald-900">Available (internal estimate)</span>
                    <span class="rounded-lg bg-amber-100 px-3 py-2 text-amber-900">On hold</span>
                    <span class="rounded-lg bg-rose-100 px-3 py-2 text-rose-900">Confirmed claim</span>
                    <span class="rounded-lg bg-orange-100 px-3 py-2 text-orange-900">Cleanup required</span>
                    <span class="rounded-lg bg-gray-200 px-3 py-2 text-gray-900">Unconfigured</span>
                </div>
                @php($canViewDrafts = $this->canOpenDraftProposals())
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full min-w-[480px] border-collapse text-left text-sm">
                        <caption class="sr-only">Read-only morning, noon and evening occupancy status for each date</caption>
                        <thead class="bg-gray-100 text-gray-900">
                            <tr>
                                <th scope="col" class="p-3">Date</th>
                                <th scope="col" class="p-3">Morning</th>
                                <th scope="col" class="p-3">Noon</th>
                                <th scope="col" class="p-3">Evening</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($projection['days'] as $day)
                                <tr class="border-t" wire:key="hall-day-{{ $day['date'] }}">
                                    <th scope="row" class="p-3 font-medium">
                                        <span class="block">{{ $day['date'] }}</span>
                                        @if ($canViewDrafts)
                                            <a class="mt-1 block text-xs underline"
                                                href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId, 'hall' => $hallId, 'from' => $day['date'], 'to' => $day['date']]) }}">
                                                View draft proposals
                                            </a>
                                        @endif
                                    </th>
                                    @foreach (['morning', 'noon', 'evening'] as $program)
                                        @php
                                            $slot = $day['programs'][$program];
                                            $status = $slot['status'];
                                            $label = match ($status) {
                                                'available' => 'Available',
                                                'hold' => 'On hold',
                                                'confirmed' => 'Confirmed claim',
                                                'cleanup_required' => 'Cleanup required',
                                                default => 'Unconfigured',
                                            };
                                            $style = match ($status) {
                                                'available' => 'bg-emerald-100 text-emerald-900',
                                                'hold' => 'bg-amber-100 text-amber-900',
                                                'confirmed' => 'bg-rose-100 text-rose-900',
                                                'cleanup_required' => 'bg-orange-100 text-orange-900',
                                                default => 'bg-gray-200 text-gray-900',
                                            };
                                        @endphp
                                        <td class="p-3">
                                            <span class="inline-flex rounded-lg px-3 py-2 font-medium {{ $style }}">
                                                {{ $label }}
                                            </span>
                                            @if ($status === 'hold')
                                                <span class="mt-1 block text-xs">Expires: {{ $slot['expiresAt'] }}</span>
                                            @endif
                                            @if ($canViewDrafts && ($proposalCounts[$day['date']][$program] ?? 0) > 0)
                                                <a class="mt-1 block text-xs underline"
                                                    href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId, 'hall' => $hallId, 'from' => $day['date'], 'to' => $day['date'], 'program' => $program]) }}">
                                                    {{ $proposalCounts[$day['date']][$program] }} unconfirmed
                                                    {{ ($proposalCounts[$day['date']][$program] === 1) ? 'draft' : 'drafts' }}
                                                    (not reserved) · review
                                                </a>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs">
                    Draft proposals are separate from physical slot claims and do not reserve this hall.
                    The displayed snapshot can change immediately after refresh. Do not promise a reservation based on this table.
                    Timezone, hold duration and booking approval policy require venue signoff.
                </p>
            </section>
        @endif
    </div>
</x-filament-panels::page>
