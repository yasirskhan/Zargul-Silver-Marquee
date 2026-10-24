<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Private enquiries submitted from the published website. Owner/admin only; customer contact is never public.
            An enquiry is not a booking, hold or payment.
        </p>

        <label class="block max-w-sm space-y-1">
            <span class="text-sm font-medium">Company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        <div class="flex gap-3">
            <button type="button" wire:click="refresh" class="rounded-lg border px-4 py-2 text-sm font-semibold">Refresh</button>
        </div>

        <section aria-label="Private venue enquiries" class="space-y-4">
            @forelse ($rows as $row)
                <article wire:key="enquiry-{{ $row['id'] }}" class="space-y-3 rounded-xl border p-4 text-sm">
                    <div class="flex flex-wrap justify-between gap-2">
                        <h2 class="font-semibold">{{ $row['customer_name'] }}</h2>
                        <span class="rounded border px-2 py-1">{{ strtoupper($row['status']) }}</span>
                    </div>
                    <p class="break-all">
                        {{ $row['email'] ?: 'No email' }} · {{ $row['phone'] ?: 'No phone' }}
                    </p>
                    <p>
                        {{ $row['event_date'] }} · {{ ucfirst($row['program']) }} · {{ $row['guests'] }} guests
                        @if ($row['hall_name']) · {{ $row['hall_name'] }} @endif
                    </p>
                    @if ($row['message'])
                        <p class="whitespace-pre-line rounded-lg bg-gray-100 p-3 text-gray-900">{{ $row['message'] }}</p>
                    @endif
                    <p class="text-xs">Received {{ $row['created_at'] }} · reference {{ $row['id'] }}</p>

                    <div class="grid gap-2 md:grid-cols-[1fr_auto]">
                        <label class="block space-y-1">
                            <span class="text-xs font-medium">Triage reason (no extra customer data)</span>
                            <input type="text" maxlength="300" wire:model="reasons.{{ $row['id'] }}"
                                class="w-full rounded-lg border p-2 text-gray-900">
                        </label>
                        <div class="flex flex-wrap items-end gap-2">
                            <button type="button" wire:click="updateStatus('{{ $row['id'] }}', 'contacted')"
                                class="rounded-lg border px-3 py-2">Mark contacted</button>
                            <button type="button" wire:click="updateStatus('{{ $row['id'] }}', 'closed')"
                                class="rounded-lg border px-3 py-2">Close</button>
                            <button type="button" wire:click="updateStatus('{{ $row['id'] }}', 'new')"
                                class="rounded-lg border px-3 py-2">Reopen</button>
                        </div>
                    </div>
                    @error('reasons.'.$row['id']) <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                </article>
            @empty
                <p class="rounded-lg border p-4 text-sm">No website enquiries for this company yet.</p>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
