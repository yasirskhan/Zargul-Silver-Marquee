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
                            <p class="text-sm">Unconfirmed company proposals</p>
                            <p class="text-3xl font-semibold">{{ $summary['drafts'] }}</p>
                            <p class="text-xs">Draft or under review · not a reservation</p>
                        </div>
                    @endif
                    @if ($summary['canReadApprovals'])
                        <div class="rounded-xl border p-4">
                            <p class="text-sm">Pending approval requests in your view</p>
                            <p class="text-3xl font-semibold">{{ $summary['pendingApprovals'] }}</p>
                            <p class="text-xs">Manager counts include only their own requests</p>
                        </div>
                    @endif
                </div>
                <button type="button" wire:click="refreshSummary"
                    class="rounded-lg border px-4 py-2 text-sm">Refresh overview</button>
            </section>
            <section class="space-y-3" aria-label="Daily staff quick navigation">
                <h2 class="font-semibold">Go directly to your work</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @if ($summary['visibleHalls'] > 0)
                        <a class="rounded-xl border p-4 underline" href="{{ \App\Filament\Pages\HallCalendar::getUrl() }}">
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
                            href="{{ \App\Filament\Pages\HallPrograms::getUrl() }}">
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
