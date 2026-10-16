<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Internal booking proposals, owner/admin read-only. These are NOT confirmed bookings or holds.
            Availability, prices, consent and booking approval must be checked separately. No customer contact is displayed.
        </p>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <label class="block space-y-2">
                <span class="text-sm font-medium">Company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->companyOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Hall</span>
                <select wire:model="hallId" class="w-full rounded-lg border p-3 text-gray-900">
                    <option value="">All company halls</option>
                    @foreach ($this->hallOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Proposal status</span>
                <select wire:model="statusFilter" class="w-full rounded-lg border p-3 text-gray-900">
                    <option value="">All states</option>
                    <option value="draft">Draft</option>
                    <option value="under_review">Under review</option>
                    <option value="withdrawn">Withdrawn</option>
                </select>
                @error('statusFilter') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Event date from</span>
                <input type="date" wire:model="fromDate" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Event date to</span>
                <input type="date" wire:model="toDate" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
        </div>
        <div>
            <button type="button" wire:click="refreshResults" class="rounded-lg border px-4 py-2 text-sm font-semibold">
                Search / refresh
            </button>
            @error('fromDate') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        @if ($loadedCompanyId === $companyId)
            <section class="space-y-4" aria-label="Private company proposal results">
                <p class="text-sm">Showing {{ count($entries) }} internal proposal(s), starting at {{ $offset + 1 }}.</p>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full min-w-[630px] border-collapse text-left text-sm">
                        <caption class="sr-only">Unconfirmed owner-only proposal metadata, no customer contact details</caption>
                        <thead class="bg-gray-100 text-gray-900">
                            <tr>
                                <th scope="col" class="p-3">Date</th>
                                <th scope="col" class="p-3">Hall</th>
                                <th scope="col" class="p-3">Program</th>
                                <th scope="col" class="p-3">Guests</th>
                                <th scope="col" class="p-3">Status</th>
                                <th scope="col" class="p-3">Revision</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $item)
                                <tr wire:key="booking-proposal-{{ $item['id'] }}" class="border-t">
                                    <td class="p-3">{{ $item['eventDate'] }}</td>
                                    <td class="p-3">{{ $item['hallName'] }}</td>
                                    <td class="p-3">{{ ucfirst($item['program']) }}</td>
                                    <td class="p-3">{{ $item['guests'] }}</td>
                                    <td class="p-3 font-medium">{{ str_replace('_', ' ', ucfirst($item['status'])) }}</td>
                                    <td class="p-3">{{ $item['revision'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="p-4 text-sm">No matching internal proposals.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <nav aria-label="Proposal pagination" class="flex items-center gap-3">
                    <button type="button" wire:click="previousPage" @disabled($offset === 0) class="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Previous</button>
                    <button type="button" wire:click="nextPage" @disabled(! $hasMore || $offset > 5000 - 25) class="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Next</button>
                </nav>
                <p class="text-xs">Read-only. No action here reserves a hall or confirms an event.</p>
            </section>
        @endif
    </div>
</x-filament-panels::page>
