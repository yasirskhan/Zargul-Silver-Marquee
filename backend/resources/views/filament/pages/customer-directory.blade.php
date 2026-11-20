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
        @php($rows = $this->rows())
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
                    <thead><tr><th>Customer</th><th>Email</th><th>Phone</th><th>Reservations</th><th>Last event</th><th>Revision</th></tr></thead>
                    <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="customer-{{ $row['id'] }}">
                            <td><strong>{{ $row['name'] }}</strong><small class="block break-all">{{ $row['id'] }}</small></td>
                            <td>{{ $row['email'] !== '' ? $row['email'] : '—' }}</td>
                            <td>{{ $row['phone'] !== '' ? $row['phone'] : '—' }}</td>
                            <td>{{ $row['bookings'] }}</td>
                            <td>{{ $row['last'] ?? '—' }}</td>
                            <td>{{ $row['revision'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No customers match this search.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
