<x-filament-panels::page>
    @php($ready = $loadedCompanyId === $companyId && $summary !== [])
    <div class="space-y-5">
        <header class="vd-pagehead">
            <div>
                <span class="vd-eyebrow">VenueDesk · Operations</span>
                <h1>Business Overview</h1>
                <p>Your private operations workspace. Counts reflect only your current company and role.</p>
            </div>
            <div class="vd-actions">
                @if (\App\Filament\Pages\HallCalendar::canAccess())
                    <a href="{{ \App\Filament\Pages\HallCalendar::getUrl(['company' => $companyId]) }}">Calendar &amp; availability</a>
                @endif
                @if (\App\Filament\Pages\BookingProposals::canAccess())
                    <a href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId]) }}">New booking proposal</a>
                @endif
                @if (\App\Filament\Pages\FinanceDesk::canAccess())
                    <a href="{{ \App\Filament\Pages\FinanceDesk::getUrl(['company' => $companyId]) }}">Invoices &amp; payments</a>
                @endif
                @if (\App\Filament\Pages\ApprovalCenter::canAccess())
                    <a href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $companyId]) }}">Approval Center</a>
                @endif
            </div>
        </header>

        <label class="block max-w-sm space-y-1">
            <span class="text-sm font-medium">Operating company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        @if ($ready)
            @if ($summary['licenseRestricted'] ?? false)
                <section class="rounded-xl border p-4 space-y-2" role="status" aria-label="Venue licensing access">
                    <p class="font-semibold">Venue operations are unavailable until a valid HAFZATECH-signed license or its approved management grace is active.</p>
                    <p class="text-sm">Your bookings and financial records remain stored. Authorized support and licensing access remain available.</p>
                    <a class="underline text-sm" href="{{ \App\Filament\Pages\Licensing::getUrl(['company' => $companyId]) }}">Open Licensing</a>
                </section>
            @endif

            @if ($summary['canSeeCompanyProposals'])
                <section class="vd-metrics" aria-label="VenueDesk business metrics">
                    <div class="vd-metric"><span class="vd-bubble">✓</span><div><b>{{ number_format($summary['confirmedUpcoming'] ?? 0) }}</b><span>Confirmed upcoming bookings</span></div></div>
                    <div class="vd-metric"><span class="vd-bubble">▦</span><div><b>{{ number_format($summary['programSlotsToday'] ?? 0) }}</b><span>Program slots today</span></div></div>
                    <div class="vd-metric"><span class="vd-bubble">◈</span><div><b>{{ number_format($summary['pendingApprovals'] ?? 0) }}</b><span>Pending approvals</span></div></div>
                    <div class="vd-metric"><span class="vd-bubble">₨</span><div><b>{{ number_format(($summary['outstandingMinor'] ?? 0) / 100, 2) }}</b><span>PKR outstanding invoice balance</span></div></div>
                </section>
            @endif

            <div class="vd-dashboard-grid">
                <section class="vd-panel" aria-label="Recent booking proposals">
                    <div class="vd-panel-head"><h2>Recent booking proposals</h2><p>Draft and under review · not reservations</p></div>
                    @if (($summary['recentProposals'] ?? []) !== [])
                        <div class="overflow-x-auto"><table class="vd-table"><thead><tr><th>Date</th><th>Hall</th><th>Program</th><th>Status</th></tr></thead><tbody>
                            @foreach ($summary['recentProposals'] as $proposal)
                                <tr><td>{{ $proposal['eventDate'] }}</td><td>{{ $proposal['hallName'] }}</td><td>{{ ucfirst($proposal['program']) }}</td><td><span class="vd-badge {{ $proposal['status'] === 'under_review' ? 'warn' : '' }}">{{ str_replace('_',' ',$proposal['status']) }}</span></td></tr>
                            @endforeach
                        </tbody></table></div>
                    @else
                        <p class="text-sm text-gray-600">No draft proposals in your workspace.</p>
                    @endif
                </section>

                <section class="vd-panel" aria-label="VenueDesk quick actions">
                    <div class="vd-panel-head"><h2>Quick actions</h2><p>Common desk tasks, using your current company and role.</p></div>
                    <div class="vd-quick-grid">
                        @if (\App\Filament\Pages\HallCalendar::canAccess())<a class="vd-action-card" href="{{ \App\Filament\Pages\HallCalendar::getUrl(['company' => $companyId]) }}"><strong>Check availability</strong><span>Calendar, holds and hall programs</span></a>@endif
                        @if (\App\Filament\Pages\BookingProposals::canAccess())<a class="vd-action-card" href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId]) }}"><strong>Create booking</strong><span>Internal proposal first, no instant reservation</span></a>@endif
                        @if (\App\Filament\Pages\FinanceDesk::canAccess())<a class="vd-action-card" href="{{ \App\Filament\Pages\FinanceDesk::getUrl(['company' => $companyId]) }}"><strong>Find invoice</strong><span>Invoices, payments and balances</span></a>@endif
                        @if (\App\Filament\Pages\ApprovalCenter::canAccess())<a class="vd-action-card" href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $companyId]) }}"><strong>Review approvals</strong><span>Discounts and protected decisions</span></a>@endif
                        @if (\App\Filament\Pages\WebsiteWorkspace::canAccess())<a class="vd-action-card" href="{{ \App\Filament\Pages\WebsiteWorkspace::getUrl(['company' => $companyId]) }}"><strong>Website editor</strong><span>Draft, media and publication</span></a>@endif
                        @if (\App\Filament\Pages\HallPhotos::canAccess())<a class="vd-action-card" href="{{ \App\Filament\Pages\HallPhotos::getUrl(['company' => $companyId]) }}"><strong>Hall photography</strong><span>Approved images for each hall</span></a>@endif
                    </div>
                </section>
            </div>

            <details class="vd-details">
                <summary>Your workspace · {{ ucfirst($summary['role']) }} — detailed counts</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-xl border p-4"><p class="text-sm">Halls visible to you</p><p class="text-3xl font-semibold">{{ $summary['visibleHalls'] }}</p><p class="text-xs">Based on current hall assignments and membership</p></div>
                    @if ($summary['canSeeCompanyProposals'])
                        <div class="rounded-xl border p-4"><p class="text-sm">Halls needing program setup</p><p class="text-3xl font-semibold">{{ $summary['hallsNeedingSetup'] }}</p><p class="text-xs">Configure morning, noon and evening before confirming bookings</p></div>
                        <div class="rounded-xl border p-4"><p class="text-sm">Unconfirmed company proposals</p><p class="text-3xl font-semibold">{{ $summary['drafts'] }}</p><p class="text-xs">Draft or under review · not a reservation</p></div>
                        <div class="rounded-xl border p-4"><p class="text-sm">Proposals awaiting internal review</p><p class="text-3xl font-semibold">{{ $summary['reviewDrafts'] }}</p><p class="text-xs">Submitted draft requests only</p></div>
                        <div class="rounded-xl border p-4"><p class="text-sm">Next 7 days · unconfirmed proposals</p><p class="text-3xl font-semibold">{{ $summary['upcomingDrafts'] }}</p><p class="text-xs">{{ $summary['upcomingFrom'] }} to {{ $summary['upcomingThrough'] }}</p></div>
                    @endif
                    <div class="rounded-xl border p-4"><p class="text-sm">Pending approval requests</p><p class="text-3xl font-semibold">{{ $summary['pendingApprovals'] ?? 0 }}</p><p class="text-xs">Manager counts include only their own requests</p><a class="text-xs underline" href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $companyId]) }}">Review pending approvals</a></div>
                </div>
                <div class="vd-actions mt-4">
                    <a href="{{ \App\Filament\Pages\VenueReservations::getUrl(['company' => $companyId]) }}">Manage confirmed reservations</a>
                    <a href="{{ \App\Filament\Pages\InternalQuickSearch::getUrl(['company' => $companyId]) }}">Universal staff search</a>
                    <a href="{{ \App\Filament\Pages\HallPrograms::getUrl(['company' => $companyId]) }}">Hall program rules</a>
                    <a href="{{ \App\Filament\Pages\WebsiteWorkspace::getUrl(['company' => $companyId]) }}">Website editor</a>
                    <a href="{{ \App\Filament\Pages\AuditHistory::getUrl(['company' => $companyId]) }}">Audit history</a>
                </div>
            </details>

            <section class="vd-panel vd-note-panel" aria-label="VenueDesk operations note" style="margin-top:16px">
                <div class="vd-panel-head"><h2>Operations note</h2></div>
                <p>Bookings, payments and approvals are backed by protected services. Opening a proposal does not reserve a hall, and viewing an invoice does not verify payment.</p>
            </section>
        @else
            <section class="vd-panel"><p>Refresh the overview to load the selected company.</p></section>
        @endif
    </div>
</x-filament-panels::page>
