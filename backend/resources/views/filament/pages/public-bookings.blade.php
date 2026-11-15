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
                <p>Total: {{ number_format($booking->total_minor / 100, 2) }} {{ $booking->currency_code }}</p>
                <p>Verified deposit: {{ number_format($booking->deposit_minor / 100, 2) }} {{ $booking->currency_code }}</p>
                <p>Confirmed: {{ $booking->confirmed_at }}</p>
                @if ($booking->finance)
                    <div class="mt-2 rounded border border-emerald-600 p-3 text-sm">
                        <p><strong>Invoice {{ $booking->finance->invoice_number }}</strong></p>
                        <p>Paid: {{ number_format($booking->finance->paid_minor / 100, 2) }} {{ $booking->finance->currency_code }}</p>
                        <p>Outstanding: {{ number_format($booking->finance->due_minor / 100, 2) }} {{ $booking->finance->currency_code }}</p>
                    </div>
                @else
                    <button type="button" wire:click="connectFinance('{{ $booking->id }}')"
                        class="mt-2 rounded-lg bg-emerald-800 px-4 py-2 text-sm font-semibold text-white">
                        Create reservation, invoice and deposit receipt
                    </button>
                @endif
            </section>
        @empty
            <p>No confirmed public bookings.</p>
        @endforelse
    </div>
</x-filament-panels::page>