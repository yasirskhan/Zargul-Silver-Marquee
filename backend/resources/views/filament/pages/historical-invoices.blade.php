<x-filament-panels::page>
    <section class="space-y-5" aria-label="Private historical invoices">
        <h2 class="text-lg font-semibold">Past invoices and payment history</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Read-only historical financial records for a verified owner or administrator.
            These records remain accessible after a signed license's operational grace ends.
            This page does not create invoices, accept payments, approve refunds or change balances.
        </p>
        <label class="block space-y-1">
            <span class="text-sm font-medium">Operating company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <button type="button" wire:click="refreshInvoices" class="rounded-lg border px-4 py-2 text-sm">
            Refresh historical invoices
        </button>
        @if ($loadedCompanyId === $companyId)
            <p class="text-xs">Showing up to 25 invoices from position {{ $offset + 1 }} for the selected company. Older records remain available through pagination.</p>
            <div class="space-y-2">
                @forelse ($invoices as $invoice)
                    <article wire:key="historic-invoice-{{ $invoice['id'] }}" class="rounded-lg border p-3 text-sm">
                        <strong>{{ $invoice['number'] }}</strong>
                        — {{ $invoice['currency'] }} {{ number_format($invoice['totalMinor'] / 100, 2) }}
                        — issued {{ $invoice['issuedAt'] }}
                    </article>
                @empty
                    <p class="rounded-lg border p-4 text-sm">No existing invoices found for this company.</p>
                @endforelse
            </div>
            <nav aria-label="Historical invoice pagination" class="flex items-center gap-3">
                <button type="button" wire:click="previousPage" @disabled($offset === 0)
                    class="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Previous</button>
                <button type="button" wire:click="nextPage" @disabled(! $hasMore || $offset >= 5000 - 25)
                    class="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Next</button>
            </nav>
            @if ($invoices !== [])
                <form wire:submit="viewStatement" class="space-y-3">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Existing invoice statement</span>
                        <select wire:model="selectedInvoiceId" class="w-full rounded-lg border p-3 text-gray-900" required>
                            <option value="">Select an invoice</option>
                            @foreach ($invoices as $invoice)
                                <option value="{{ $invoice['id'] }}">{{ $invoice['number'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="rounded-lg border px-4 py-2 text-sm font-semibold">
                        View payment and refund history
                    </button>
                </form>
            @endif
            @if ($statement !== null)
                <section class="rounded-lg border p-4 space-y-3" aria-label="Existing invoice statement">
                    <h3 class="font-semibold">Verified local accounting history — {{ $statement['currency'] }}</h3>
                    <dl class="grid gap-2 sm:grid-cols-2 text-sm">
                        <div><dt>Invoice total</dt><dd>{{ number_format($statement['totalMinor'] / 100, 2) }}</dd></div>
                        <div><dt>Net recorded payments</dt><dd>{{ number_format($statement['paidMinor'] / 100, 2) }}</dd></div>
                        <div><dt>Recorded refunds</dt><dd>{{ number_format($statement['refundedMinor'] / 100, 2) }}</dd></div>
                        <div><dt>Remaining amount</dt><dd>{{ number_format($statement['dueMinor'] / 100, 2) }}</dd></div>
                    </dl>
                    <h4 class="font-semibold">Recorded receipts</h4>
                    @forelse ($statement['payments'] as $payment)
                        <p class="text-sm">{{ $payment['receiptNumber'] }} — {{ $payment['currency'] }} {{ number_format($payment['amountMinor'] / 100, 2) }}</p>
                    @empty
                        <p class="text-sm">No recorded payments.</p>
                    @endforelse
                    <h4 class="font-semibold">Recorded refunds</h4>
                    @forelse ($statement['refunds'] as $refund)
                        <p class="text-sm">{{ $refund['refundNumber'] }} — {{ number_format($refund['amountMinor'] / 100, 2) }}</p>
                    @empty
                        <p class="text-sm">No recorded refunds.</p>
                    @endforelse
                </section>
            @endif
        @endif
    </section>
</x-filament-panels::page>
