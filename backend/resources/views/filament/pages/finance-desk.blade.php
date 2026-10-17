<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-700 dark:text-gray-300">
            Finance review is internal only. A rental-price preview is NOT an invoice, quote to a client,
            receipt, tax calculation or authorization to charge. Payments and refunds are NOT connected.
        </p>
        <label class="block space-y-1">
            <span class="text-sm font-medium">Company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        @if ($loadedCompanyId === $companyId)
            <section aria-label="Create frozen internal rental preview" class="space-y-3 rounded-xl border p-4">
                <h2 class="text-lg font-semibold">Freeze internal rental-price preview</h2>
                <p class="text-sm">Uses the saved hall program's approved revision, never a fabricated rate.
                    Excludes unapproved addon quantities, tax, discounts and payment claims.</p>
                <label class="block space-y-1"><span class="text-sm">Internal proposal under review</span>
                    <select wire:model="draftBookingId" class="w-full rounded-lg border p-3 text-gray-900">
                        <option value="">Select a proposal</option>
                        @foreach ($this->proposalOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block space-y-1"><span class="text-sm">Internal purpose (no customer contact)</span>
                    <textarea wire:model="previewReason" rows="2" maxlength="500" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                </label>
                @error('previewReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="button" wire:click="createPreview" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2 font-semibold">
                    Save immutable internal preview
                </button>
                <h3 class="font-semibold">Private frozen previews</h3>
                @if ($previewId !== '')
                    <p class="break-all text-xs">Showing only selected frozen preview {{ $previewId }}.</p>
                    <button type="button" wire:click="showAllPreviews"
                        class="rounded-lg border px-3 py-2 text-sm underline">Show all company rental previews</button>
                @endif
                <div class="space-y-2">
                    @forelse ($previews as $item)
                        <article wire:key="preview-{{ $item['id'] }}" class="rounded-lg border p-3 text-sm">
                            <p class="font-medium">Nonbinding {{ $item['currency'] }} {{ $item['subtotalMinor'] }} minor units</p>
                            <p>Proposal revision {{ $item['bookingRevision'] }}, approved program rules {{ $item['sourceRevision'] }}</p>
                            <ul class="space-y-1 text-xs" aria-label="Immutable rental preview line items">
                                @foreach ($item['lines'] as $line)
                                    <li>
                                        {{ ucwords(str_replace('_', ' ', $line['code'])) }}
                                        ({{ ucfirst($line['program']) }}):
                                        {{ $line['quantity'] }} × rate {{ $line['rateMinor'] }}
                                        = {{ $line['amountMinor'] }} minor units
                                    </li>
                                @endforeach
                            </ul>
                            <p class="break-all text-xs">Preview {{ $item['id'] }} | Proposal {{ $item['bookingId'] }}</p>
                            <a class="inline-flex rounded-lg border px-3 py-2 underline"
                                href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId, 'proposal' => $item['bookingId']]) }}">
                                Review linked proposal
                            </a>
                        </article>
                    @empty
                        <p class="text-sm">No internal rental previews.</p>
                    @endforelse
                </div>
            </section>
            <section class="space-y-3 rounded-xl border p-4" aria-label="Daily immutable receipt reconciliation">
                <h2 class="text-lg font-semibold">Daily invoice receipt and refund reconciliation (UTC)</h2>
                <p class="text-xs">Read-only comparison of recorded cash/bank deposits and approved reversals with their existing ledger journals. Amounts in minor units; no payout or bank transfer is initiated.</p>
                <label class="block space-y-1">
                    <span class="text-sm">UTC day</span>
                    <input type="date" wire:model="reconciliationDate" class="rounded-lg border p-3 text-gray-900">
                </label>
                <button type="button" wire:click="refreshReconciliation"
                    class="rounded-lg border px-3 py-2">Refresh daily reconciliation</button>
                @error('reconciliationDate') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @if (($dailyReconciliation['utcDate'] ?? '') === $reconciliationDate)
                    @forelse (($dailyReconciliation['currencies'] ?? []) as $bucket)
                        <article class="space-y-1 rounded-lg border p-3 text-sm">
                            <strong>{{ $bucket['currency'] }} · {{ $dailyReconciliation['utcDate'] }}</strong>
                            <p>Cash received {{ $bucket['cashReceivedMinor'] }} · bank received {{ $bucket['bankReceivedMinor'] }}</p>
                            <p>Cash refunded {{ $bucket['cashRefundedMinor'] }} · bank refunded {{ $bucket['bankRefundedMinor'] }}</p>
                            <p>Net cash {{ $bucket['netCashMinor'] }} · net bank {{ $bucket['netBankMinor'] }}</p>
                            <p>Receipts {{ $bucket['receiptCount'] }} · refunds {{ $bucket['refundCount'] }}
                                · ledger verification {{ $bucket['ledgerMatched'] ? 'matched' : 'DISCREPANCY — REVIEW REQUIRED' }}</p>
                        </article>
                    @empty
                        <p class="text-sm">No customer receipts or approved refunds recorded on this UTC date.</p>
                    @endforelse
                    @if (count($dailyReconciliation['cashCloseouts'] ?? []) > 0)
                        <h3 class="font-semibold">Recorded UTC cash-drawer closeouts (independent of deposit net)</h3>
                        @foreach ($dailyReconciliation['cashCloseouts'] as $closeout)
                            <p class="rounded-lg border p-3 text-sm">
                                {{ $closeout['currency'] }}: expected {{ $closeout['expectedMinor'] }},
                                physically counted {{ $closeout['countedMinor'] }},
                                variance {{ $closeout['varianceMinor'] }} minor units.
                                This historical count is not automatically adjusted.
                            </p>
                        @endforeach
                    @endif
                @endif
            </section>
            <section aria-label="Controlled cash ledger and daily reconciliation" class="space-y-3 rounded-xl border p-4">
                <h2 class="text-lg font-semibold">Controlled cash ledger</h2>
                @if (! $this->postingEnabled())
                    <p class="text-sm font-semibold">
                        Financial posting is disabled until operator-approved finance, currency, tax,
                        refund, contract and cash-custody rules are accepted. No receipts or real cash
                        movements are recorded on this page.
                    </p>
                @else
                    <p class="text-sm">Only a verified owner can open, reverse or close a cash balance.
                        Every posting is double-entry and immutable; real customer collections are not available.</p>
                    @if ($this->role() === 'owner')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block space-y-1"><span class="text-sm">ISO currency</span>
                                <input type="text" maxlength="3" wire:model="currency" class="w-full rounded-lg border p-3 text-gray-900">
                            </label>
                            <label class="block space-y-1"><span class="text-sm">Opening cash minor units</span>
                                <input type="number" min="1" wire:model="openingMinor" class="w-full rounded-lg border p-3 text-gray-900">
                            </label>
                        </div>
                        <label class="block space-y-1"><span class="text-sm">Opening reason</span>
                            <input type="text" maxlength="500" wire:model="journalReason" class="w-full rounded-lg border p-3 text-gray-900">
                        </label>
                        @error('journalReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="openCash" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2">
                            Record controlled opening (double-entry)
                        </button>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block space-y-1"><span class="text-sm">UTC cash-close date</span>
                                <input type="date" wire:model="closeDate" class="w-full rounded-lg border p-3 text-gray-900">
                            </label>
                            <label class="block space-y-1"><span class="text-sm">Physically counted minor units</span>
                                <input type="number" min="0" wire:model="countedMinor" class="w-full rounded-lg border p-3 text-gray-900">
                            </label>
                        </div>
                        <label class="block space-y-1"><span class="text-sm">Cash handover reason</span>
                            <input type="text" maxlength="500" wire:model="closeReason" class="w-full rounded-lg border p-3 text-gray-900">
                        </label>
                        @error('closeReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="button" wire:click="closeCash" wire:loading.attr="disabled" class="rounded-lg border px-4 py-2">
                            Freeze immutable daily cash count
                        </button>
                        @if ($lastCloseResult)
                            <p class="text-sm">Counted {{ $lastCloseResult['countedMinor'] }},
                                expected {{ $lastCloseResult['expectedMinor'] }},
                                variance {{ $lastCloseResult['varianceMinor'] }} in {{ $lastCloseResult['currency'] }} minor units.
                                A variance is NOT automatically adjusted.</p>
                        @endif
                    @endif
                @endif
                <h3 class="font-semibold">Immutable journal records</h3>
                <div class="space-y-2">
                    @forelse ($journals as $journal)
                        <article wire:key="journal-{{ $journal['id'] }}" class="space-y-1 rounded-lg border p-3 text-sm">
                            <p>{{ str_replace('_', ' ', $journal['entry_type']) }} | {{ $journal['currency_code'] }}
                                {{ $journal['amount_minor'] }} minor units</p>
                            <p class="break-all text-xs">Journal {{ $journal['id'] }}; reverses {{ $journal['reversal_of'] ?? 'none' }}</p>
                            @if ($this->postingEnabled() && $this->role() === 'owner' && $journal['entry_type'] === 'opening_cash')
                                <label class="block space-y-1"><span class="text-sm">Reason to reverse an opening (never delete history)</span>
                                    <input type="text" maxlength="500" wire:model="reversalReason" class="w-full rounded-lg border p-3 text-gray-900">
                                </label>
                                @error('reversalReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                                <button type="button" wire:click="reverseOpening('{{ $journal['id'] }}')" class="rounded-lg border px-3 py-2">Append reversal</button>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm">No recorded journals.</p>
                    @endforelse
                </div>
                <h3 class="font-semibold">Immutable UTC cash closeouts</h3>
                <div class="space-y-2">
                    @forelse ($closeouts as $close)
                        <article wire:key="cash-close-{{ $close['id'] }}" class="rounded-lg border p-3 text-sm">
                            {{ $close['close_date'] }} | {{ $close['currency_code'] }},
                            expected {{ $close['expected_minor'] }}, counted {{ $close['counted_minor'] }},
                            variance {{ $close['variance_minor'] }}
                        </article>
                    @empty
                        <p class="text-sm">No UTC cash days have been closed.</p>
                    @endforelse
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
