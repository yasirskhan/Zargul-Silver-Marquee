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
                    @if (in_array($summary['role'] ?? '', ['owner','admin'], true))
                        <a class="underline text-sm" href="{{ \App\Filament\Pages\Licensing::getUrl(['company' => $companyId]) }}">Open Licensing</a>
                        <a class="ml-3 underline text-sm" href="{{ \App\Filament\Pages\SupportInbox::getUrl(['company' => $companyId]) }}">Contact HAFZATECH Support</a>
                    @endif
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

            <section aria-label="Private venue overview"><details class="vd-details" open>
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
                    @if ($summary['canPlan'] ?? false)
                        <a href="{{ \App\Filament\Pages\VenueReservations::getUrl(['company' => $companyId]) }}">Manage confirmed reservations</a>
                    @endif
                    @if ($summary['canSearch'] ?? false)
                        <a href="{{ \App\Filament\Pages\InternalQuickSearch::getUrl(['company' => $companyId]) }}">Universal staff search</a>
                    @endif
                    @if ($summary['canManageHallRules'] ?? false)
                        <a href="{{ \App\Filament\Pages\HallPrograms::getUrl(['company' => $companyId]) }}">Hall program rules</a>
                    @endif
                    @if ($summary['canEditWebsite'] ?? false)
                        <a href="{{ \App\Filament\Pages\WebsiteWorkspace::getUrl(['company' => $companyId]) }}">Website editor</a>
                    @endif
                    @if ($summary['canReadAudit'] ?? false)
                        <a href="{{ \App\Filament\Pages\AuditHistory::getUrl(['company' => $companyId]) }}">Audit history</a>
                    @endif
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="rounded border p-3 text-sm">
                        <p class="font-semibold">Owner shortcuts</p>
                        <ul class="mt-1 space-y-1">
                            @if ($summary['canSeeCompanyProposals'] ?? false)
                                <li><a class="underline" href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId]) }}">Review booking proposals</a></li>
                                <li><a class="underline" href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId]) }}">Open internal proposal review queue</a></li>
                            @endif
                            @if ($summary['canReadApprovals'] ?? false)
                                <li><a class="underline" href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $companyId]) }}">Approval center</a> · <a class="underline" href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $companyId]) }}">Review pending approvals</a></li>
                            @endif
                            @if ($summary['canReviewFinance'] ?? false)
                                <li>Finance review · internal only</li>
                            @endif
                            @if ($summary['canPlan'] ?? false)
                                <li><a class="underline" href="{{ \App\Filament\Pages\OperationsPlanning::getUrl(['company' => $companyId]) }}">Operations planning</a></li>
                                <li><a class="underline" href="{{ \App\Filament\Pages\HallCalendar::getUrl(['company' => $companyId]) }}">Hall calendar</a></li>
                            @endif
                        </ul>
                    </div>
                    <div class="rounded border p-3 text-sm">
                        <p class="font-semibold">Halls and setup</p>
                        <ul class="mt-1 space-y-1">
                            @foreach (($summary['hallSetupRows'] ?? []) as $hall)
                                <li>{{ $hall['name'] }} · {{ $hall['ready'] ? 'Ready Hall' : 'Needs Setup Hall' }}</li>
                            @endforeach
                            @if (($summary['hallsNeedingSetup'] ?? 0) > 0)
                                <li><a class="underline" href="{{ \App\Filament\Pages\HallPrograms::getUrl(['company' => $companyId, 'setup' => 'needed']) }}">Configure halls missing program rules</a></li>
                            @endif
                        </ul>
                        @if (($summary['recentPlans'] ?? []) !== [])
                            <p class="mt-2 font-semibold">Recent internal setup plans</p>
                            <ul class="space-y-1">
                                @foreach ($summary['recentPlans'] as $plan)
                                    <li>{{ $plan['title'] }} · {{ $plan['hallName'] }} · <a class="underline" href="{{ \App\Filament\Pages\OperationsPlanning::getUrl(['company' => $companyId]) }}">Open this proposal</a></li>
                                @endforeach
                            </ul>
                        @endif
                        @if (($summary['drafts'] ?? 0) > 0)
                            <p class="mt-2">Recent unconfirmed proposals · <a class="underline" href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId]) }}">Review this unconfirmed proposal</a></p>
                        @endif
                        @if (($summary['upcomingDrafts'] ?? 0) > 0)
                            <p class="mt-2">Next 7 days · <a class="underline" href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId]) }}">Review upcoming drafts (not reserved)</a></p>
                        @endif
                        @if (($summary['plannedTasks'] ?? 0) > 0)
                            <p class="mt-2">Setup planning tasks to review</p>
                        @endif
                        @if (($summary['canReviewFinance'] ?? false) && ($summary['recentPreviewReferences'] ?? []) !== [])
                            <p class="mt-2 font-semibold">Recent private rental previews</p>
                            <ul class="space-y-1">
                                @foreach ($summary['recentPreviewReferences'] as $preview)
                                    <li>Preview {{ $preview['id'] }} · <a class="underline" href="{{ \App\Filament\Pages\FinanceDesk::getUrl(['company' => $companyId]) }}">Review frozen internal preview</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                @if (($summary['hafzatechSupportQueued'] ?? null) !== null)
                    <div class="mt-3 rounded border p-3 text-sm">
                        <p><strong>Local support requests awaiting delivery</strong> · Queued locally</p>
                        <a class="underline" href="{{ \App\Filament\Pages\SupportInbox::getUrl(['company' => $companyId]) }}">Review queued support requests</a>
                        <a class="ml-3 underline" href="{{ \App\Filament\Pages\SupportInbox::getUrl(['company' => $companyId]) }}">Contact HAFZATECH Support</a>
                    </div>
                @endif
            </details></section>

            <section class="vd-panel vd-note-panel" aria-label="VenueDesk operations note" style="margin-top:16px">
                <div class="vd-panel-head"><h2>Operations note</h2></div>
                <p>Bookings, payments and approvals are backed by protected services. Opening a proposal does not reserve a hall, and viewing an invoice does not verify payment.</p>
            </section>
        @else
            <section class="vd-panel"><p>Refresh the overview to load the selected company.</p></section>
        @endif
    </div>
</x-filament-panels::page>
