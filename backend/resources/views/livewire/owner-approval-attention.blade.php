<div>
    @if ($open && count($items))
        <div class="fixed inset-0 z-[80] grid place-items-center bg-black/60 p-4"
            role="presentation">
            <section role="dialog" aria-modal="true" aria-labelledby="owner-approval-alert-title"
                class="w-full max-w-lg max-h-[90vh] space-y-4 overflow-y-auto rounded-xl bg-white p-5 text-gray-900 shadow-xl dark:bg-gray-900 dark:text-white"
                x-data x-init="$nextTick(() => $refs.review?.focus())">
                <h2 id="owner-approval-alert-title" class="text-lg font-semibold">Pending owner approval requests</h2>
                <p class="text-sm">
                    These are internal requests awaiting a separate authorized decision.
                    Reviewing one does not change bookings, charges, or payments.
                </p>
                <ul class="space-y-2 text-sm" aria-label="Pending approvals by company">
                    @foreach ($items as $item)
                        <li wire:key="owner-approval-company-{{ $item['companyId'] }}" class="rounded-lg border p-3">
                            <strong>{{ $item['company'] }}</strong>:
                            {{ $item['pending'] }} pending {{ $item['pending'] === 1 ? 'request' : 'requests' }}
                            <a href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $item['companyId']]) }}"
                                class="mt-2 block font-semibold underline">
                                Review this company's requests
                            </a>
                            @if ($item['latestRequestId'])
                                <a href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $item['companyId'], 'request' => $item['latestRequestId']]) }}"
                                    class="mt-2 block font-semibold underline">
                                    Review one pending request directly
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs">Only company-level counts and private request references are shown here. Every link requires a fresh, authorized Approval Center review.</p>
                <div class="flex flex-wrap gap-3">
                    <a x-ref="review" href="{{ \App\Filament\Pages\ApprovalCenter::getUrl() }}"
                        class="rounded-lg border px-4 py-2 font-semibold">Review in Approval Center</a>
                    <button type="button" wire:click="dismiss" class="rounded-lg border px-4 py-2">
                        Dismiss for this session
                    </button>
                </div>
            </section>
        </div>
    @endif
</div>
