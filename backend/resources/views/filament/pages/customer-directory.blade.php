<x-filament-panels::page>
    <div class="space-y-6">
        <x-venuedesk.header eyebrow="VenueDesk · Customers" title="Customer directory">
            Authorised owner and administrator view of private customer profiles and booking history.
        </x-venuedesk.header>
        <label class="block max-w-sm space-y-1"><span class="text-sm font-medium">Operating company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        @php
            $rows = $this->rows();
            $pageRows = $this->pageRows();
            $total = count($rows);
        @endphp
        <div class="vd-metrics" aria-label="Customer directory summary">
            <div class="vd-metric"><b>{{ count($rows) }}</b><span>Active customers</span></div>
            <div class="vd-metric"><b>{{ collect($rows)->sum('bookings') }}</b><span>Linked reservations</span></div>
            <div class="vd-metric"><b>{{ collect($rows)->where('bookings', 0)->count() }}</b><span>No booking yet</span></div>
        </div>
        <section class="vd-panel space-y-3">
            <div class="vd-panel-head"><h2>Customers</h2><p>Search by name, email or phone. Contact data is encrypted at rest.</p></div>
            <label class="block max-w-md space-y-1"><span class="text-sm">Search customers</span>
                <input wire:model.live.debounce.300ms="search" maxlength="120" placeholder="Name, email or phone" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
            <div class="overflow-x-auto">
                <table class="vd-table w-full text-left text-sm">
                    <thead><tr><th>Customer</th><th>Email</th><th>Phone</th><th>Reservations</th><th>Last event</th><th>Revision</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse ($pageRows as $row)
                        <tr wire:key="customer-{{ $row['id'] }}">
                            <td><strong>{{ $row['name'] }}</strong><small class="block break-all">{{ $row['id'] }}</small></td>
                            <td>{{ $row['email'] !== '' ? $row['email'] : '—' }}</td>
                            <td>{{ $row['phone'] !== '' ? $row['phone'] : '—' }}</td>
                            <td>{{ $row['bookings'] }}</td>
                            <td>{{ $row['last'] ?? '—' }}</td>
                            <td>{{ $row['revision'] }}</td>
                            <td><button type="button" wire:click="openCustomer('{{ $row['id'] }}')" class="underline">View / edit</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No customers match this search.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                <p class="text-xs">{{ $total }} customer(s) · page {{ $page }} of {{ max(1, (int) ceil($total / $perPage)) }}</p>
                <div class="flex gap-2">
                    <button type="button" wire:click="$set('page', {{ max(1, $page - 1) }})" @disabled($page <= 1) class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Previous</button>
                    <button type="button" wire:click="$set('page', {{ min(max(1, (int) ceil($total / $perPage)), $page + 1) }})" @disabled($page >= max(1, (int) ceil($total / $perPage))) class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40">Next</button>
                </div>
            </div>
        </section>
        @php $selected = $this->selectedCustomer(); @endphp
        @if ($selected !== null)
            <section class="vd-panel space-y-4" aria-label="Customer details and edit">
                <div class="vd-panel-head"><h2>{{ $selected['name'] }}</h2><p>Private customer profile · revision {{ $selected['revision'] }}</p></div>
                @if (session('customer_saved'))<p role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">{{ session('customer_saved') }}</p>@endif
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="space-y-2 text-sm">
                        <p><strong>Email:</strong> {{ $selected['email'] !== '' ? $selected['email'] : '—' }}</p>
                        <p><strong>Phone:</strong> {{ $selected['phone'] !== '' ? $selected['phone'] : '—' }}</p>
                        <p><strong>Reservations:</strong> {{ $selected['bookings'] }} · last {{ $selected['last'] ?? '—' }}</p>
                        <p class="break-all text-xs">{{ $selected['id'] }}</p>
                        <h3 class="font-semibold">Reservation history</h3>
                        @forelse ($selected['reservations'] as $reservation)
                            <p>{{ $reservation['date'] }} · {{ ucfirst($reservation['program']) }} · {{ $reservation['guests'] }} guests · {{ ucfirst($reservation['status']) }}</p>
                        @empty
                            <p>No reservations yet.</p>
                        @endforelse
                    </div>
                    <form wire:submit="saveCustomer" class="space-y-3">
                        <label class="block space-y-1"><span class="text-sm">Name</span><input wire:model="editName" maxlength="120" class="w-full rounded-lg border p-3"></label>
                        <label class="block space-y-1"><span class="text-sm">Email</span><input type="email" wire:model="editEmail" maxlength="254" class="w-full rounded-lg border p-3"></label>
                        <label class="block space-y-1"><span class="text-sm">Phone</span><input wire:model="editPhone" maxlength="32" class="w-full rounded-lg border p-3"></label>
                        <label class="block space-y-1"><span class="text-sm">Reason for change (audited)</span><textarea wire:model="editReason" rows="2" maxlength="500" class="w-full rounded-lg border p-3"></textarea></label>
                        @error('editReason')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                        <div class="flex gap-2"><button type="submit" class="rounded-lg bg-emerald-800 px-4 py-2 font-semibold text-white">Save customer</button><button type="button" wire:click="closeCustomer" class="rounded-lg border px-4 py-2">Close</button></div>
                    </form>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
