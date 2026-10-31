<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">Confirmed public bookings with independently verified synthetic deposits. No payment provider is live.</p>
        <label class="block space-y-2"><span class="text-sm font-medium">Company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        @forelse ($this->bookings() as $booking)
            <section class="rounded-lg border p-4" wire:key="public-booking-{{ $booking->id }}">
                <h2 class="font-semibold">{{ $booking->event_date }} · {{ ucfirst($booking->program) }} · {{ $booking->guests }} guests</h2>
                <p>Customer: {{ $booking->customer_name }}</p>
                <p>Total: {{ number_format($booking->total_minor) }} {{ $booking->currency_code }} minor units</p>
                <p>Verified deposit: {{ number_format($booking->deposit_minor) }} {{ $booking->currency_code }} minor units</p>
                <p>Confirmed: {{ $booking->confirmed_at }}</p>
            </section>
        @empty
            <p>No confirmed public bookings.</p>
        @endforelse
    </div>
</x-filament-panels::page>