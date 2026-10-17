<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Your private VenueDesk workspace. Counts reflect only your current company and role.
            Booking proposals are unconfirmed and do not reserve hall availability.
        </p>
        <label class="block space-y-1">
            <span class="text-sm font-medium">Operating company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        @if ($loadedCompanyId === $companyId && $summary !== [])
            <section class="space-y-3" aria-label="Private venue overview">
                <h2 class="font-semibold">Your workspace · {{ ucfirst($summary['role']) }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-xl border p-4">
                        <p class="text-sm">Halls visible to you</p>
                        <p class="text-3xl font-semibold">{{ $summary['visibleHalls'] }}</p>
                        <p class="text-xs">Based on current hall assignments and membership</p>
                    </div>
                    @if ($summary['canSeeCompanyProposals'])
                        <div class="rounded-xl border p-4">
                            <p class="text-sm">Halls needing program setup</p>
                            <p class="text-3xl font-semibold">{{ $summary['hallsNeedingSetup'] }}</p>
                            <p class="text-xs">Configure morning, noon and evening before confirming any booking</p>
                            @if ($summary['hallsNeedingSetup'] > 0)
                                <a class="text-xs underline"
                                    href="{{ \App\Filament\Pages\HallPrograms::getUrl(['company' => $companyId, 'setup' => 'needed']) }}">
                                    Configure halls missing program rules
                                </a>
                            @endif
                        </div>
                        <div class="rounded-xl border p-4">
                            <p class="text-sm">Unconfirmed company proposals</p>
                            <p class="text-3xl font-semibold">{{ $summary['drafts'] }}</p>
                            <p class="text-xs">Draft or under review · not a reservation</p>
                        </div>
                    @endif
                    @if ($summary['canSeeCompanyProposals'])
                        <div class="rounded-xl border p-4">
                            <p class="text-sm">Proposals awaiting internal review</p>
                            <p class="text-3xl font-semibold">{{ $summary['reviewDrafts'] }}</p>
                            <p class="text-xs">Submitted draft requests only · not confirmed reservations</p>
                            @if ($summary['reviewDrafts'] > 0)
                                <a class="text-xs underline"
                                    href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId, 'status' => 'under_review']) }}">
                                    Open internal proposal review queue
                                </a>
                            @endif
                        </div>
                    @endif
                    @if ($summary['canSeeCompanyProposals'])
                        <div class="rounded-xl border p-4">
                            <p class="text-sm">Next 7 days · unconfirmed proposals</p>
                            <p class="text-3xl font-semibold">{{ $summary['upcomingDrafts'] }}</p>
                            <a class="text-xs underline"
                                href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId, 'from' => $summary['upcomingFrom'], 'to' => $summary['upcomingThrough']]) }}">
                                Review upcoming drafts (not reserved)
                            </a>
                        </div>
                    @endif
                    @if ($summary['canPlan'])
                        <div class="rounded-xl border p-4">
                            <p class="text-sm">Setup planning tasks to review</p>
                            <p class="text-3xl font-semibold">{{ $summary['plannedTasks'] }}</p>
                            <p class="text-xs">Only your accessible halls · planning, not actual event readiness</p>
                            @if ($summary['plannedTasks'] > 0)
                                <a class="text-xs underline"
                                    href="{{ \App\Filament\Pages\OperationsPlanning::getUrl(['company' => $companyId, 'status' => 'planned']) }}">
                                    Review planned tasks
                                </a>
                            @endif
                        </div>
                    @endif
                    @if ($summary['canReadApprovals'])
                        <div class="rounded-xl border p-4">
                            <p class="text-sm">Pending approval requests in your view</p>
                            <p class="text-3xl font-semibold">{{ $summary['pendingApprovals'] }}</p>
                            <p class="text-xs">Manager counts include only their own requests</p>
                            @if ($summary['pendingApprovals'] > 0)
                                <a class="text-xs underline"
                                    href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $companyId, 'status' => 'pending']) }}">
                                    Review pending approvals
                                </a>
                            @endif
                        </div>
                    @endif
                </div>
                <button type="button" wire:click="refreshSummary"
                    class="rounded-lg border px-4 py-2 text-sm">Refresh overview</button>
            </section>
            @if ($summary['canReviewFinance'] && count($summary['recentPreviewReferences']) > 0)
                <section class="space-y-3" aria-label="Recent nonbinding rental previews">
                    <h2 class="font-semibold">Recent private rental previews</h2>
                    <p class="text-xs">Owner/admin only · price snapshots are not invoices, bookings or payment authorizations.</p>
                    <div class="grid gap-3 md:grid-cols-3">
                        @foreach ($summary['recentPreviewReferences'] as $preview)
                            <article wire:key="recent-preview-{{ $preview['id'] }}" class="space-y-2 rounded-xl border p-4 text-sm">
                                <strong>{{ ucfirst($preview['program']) }} · internal frozen rental preview</strong>
                                <p class="break-all text-xs">Preview {{ $preview['id'] }}</p>
                                <a class="underline"
                                    href="{{ \App\Filament\Pages\FinanceDesk::getUrl(['company' => $companyId, 'preview' => $preview['id']]) }}">
                                    Review frozen internal preview
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($summary['canSeeCompanyProposals'] && count($summary['recentProposals']) > 0)
                <section class="space-y-3" aria-label="Recent unconfirmed booking proposals">
                    <h2 class="font-semibold">Recent unconfirmed proposals</h2>
                    <p class="text-xs">Owner/admin only · internal requests, not held or confirmed reservations.</p>
                    <div class="grid gap-3 md:grid-cols-3">
                        @foreach ($summary['recentProposals'] as $proposal)
                            <article wire:key="recent-proposal-{{ $proposal['id'] }}" class="space-y-2 rounded-xl border p-4 text-sm">
                                <strong>{{ $proposal['hallName'] }}</strong>
                                <p>{{ $proposal['eventDate'] }} {{ ucfirst($proposal['program']) }}</p>
                                <p class="text-xs">Request state: {{ str_replace('_', ' ', $proposal['status']) }}</p>
                                <a class="underline"
                                    href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId, 'proposal' => $proposal['id']]) }}">
                                    Review this unconfirmed proposal
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($summary['canPlan'] && count($summary['recentPlans']) > 0)
                <section class="space-y-3" aria-label="Recent internal planning tasks">
                    <h2 class="font-semibold">Recent internal setup plans</h2>
                    <p class="text-xs">Only your permitted halls. Planning never confirms a booking or records actual setup.</p>
                    <div class="grid gap-3 md:grid-cols-3">
                        @foreach ($summary['recentPlans'] as $plan)
                            <article wire:key="recent-plan-{{ $plan['id'] }}" class="space-y-2 rounded-xl border p-4 text-sm">
                                <strong>{{ $plan['title'] }}</strong>
                                <p>{{ $plan['hallName'] }} · {{ $plan['eventDate'] }} {{ ucfirst($plan['program']) }}</p>
                                <p class="text-xs">Internal status: {{ $plan['status'] }}</p>
                                <a class="underline"
                                    href="{{ \App\Filament\Pages\OperationsPlanning::getUrl(['company' => $companyId, 'proposal' => $plan['bookingId']]) }}">
                                    Open this proposal's planning tasks
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($summary['visibleHalls'] > 0 && in_array($summary['role'], ['owner', 'admin', 'manager', 'reception'], true))
                <a class="inline-flex rounded-lg border px-4 py-3 font-semibold underline"
                    href="{{ \App\Filament\Pages\VenueReservations::getUrl(['company' => $companyId]) }}">
                    Manage confirmed reservations
                </a>
            @endif
            <section class="space-y-3" aria-label="Daily staff quick navigation">
                <h2 class="font-semibold">Go directly to your work</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @if ($summary['visibleHalls'] > 0)
                        <a class="rounded-xl border p-4 underline" href="{{ \App\Filament\Pages\HallCalendar::getUrl(['company' => $companyId]) }}">
                            Hall calendar · morning, noon and evening
                        </a>
                    @endif
                    @if ($summary['canSeeCompanyProposals'])
                        <a class="rounded-xl border p-4 underline"
                            href="{{ \App\Filament\Pages\BookingProposals::getUrl(['company' => $companyId]) }}">
                            Review booking proposals
                        </a>
                    @endif
                    @if ($summary['canReadApprovals'])
                        <a class="rounded-xl border p-4 underline"
                            href="{{ \App\Filament\Pages\ApprovalCenter::getUrl(['company' => $companyId]) }}">
                            Approval center
                        </a>
                    @endif
                    @if ($summary['canReviewFinance'])
                        <a class="rounded-xl border p-4 underline"
                            href="{{ \App\Filament\Pages\FinanceDesk::getUrl(['company' => $companyId]) }}">
                            Finance review · internal only
                        </a>
                    @endif
                    @if ($summary['canPlan'])
                        <a class="rounded-xl border p-4 underline"
                            href="{{ \App\Filament\Pages\OperationsPlanning::getUrl(['company' => $companyId]) }}">
                            Operations planning
                        </a>
                    @endif
                    @if ($summary['canSearch'])
                        <a class="rounded-xl border p-4 underline"
                            href="{{ \App\Filament\Pages\InternalQuickSearch::getUrl(['company' => $companyId]) }}">
                            Universal staff search
                        </a>
                    @endif
                    @if ($summary['canManageHallRules'])
                        <a class="rounded-xl border p-4 underline"
                            href="{{ \App\Filament\Pages\HallPrograms::getUrl(['company' => $companyId]) }}">
                            Hall program rules
                        </a>
                    @endif
                    @if ($summary['canEditWebsite'])
                        <a class="rounded-xl border p-4 underline"
                            href="{{ \App\Filament\Pages\WebsiteWorkspace::getUrl(['company' => $companyId]) }}">
                            Website editor
                        </a>
                    @endif
                    @if ($summary['canReadAudit'])
                        <a class="rounded-xl border p-4 underline"
                            href="{{ \App\Filament\Pages\AuditHistory::getUrl(['company' => $companyId]) }}">
                            Audit history
                        </a>
                    @endif
                </div>
                <p class="text-xs">Navigation does not grant permissions. Every destination verifies current access again.</p>
            </section>
        @endif
    </div>
</x-filament-panels::page>
