<x-filament-panels::page>
    <section class="space-y-5" aria-label="Private HAFZATECH license status">
        <h2 class="text-lg font-semibold">License status</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            This is the private license overview for your authorized operating company.
            No payment or license can be activated from this page.
        </p>
        <label class="block space-y-1">
            <span class="text-sm font-medium">Operating company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        @if ($summary !== [])
            <dl class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border p-4">
                    <dt class="text-sm">Local license state</dt>
                    <dd class="font-semibold">{{ str_replace('_', ' ', $summary['state']) }}</dd>
                </div>
                <div class="rounded-lg border p-4">
                    <dt class="text-sm">Expiry (UTC)</dt>
                    <dd class="font-semibold">{{ $summary['expires_at'] ?? 'Not issued' }}</dd>
                </div>
                @if (!empty($summary['renewal_expires_at']))
                    <div class="rounded-lg border p-4">
                        <dt class="text-sm">Prepaid renewal valid through (UTC)</dt>
                        <dd class="font-semibold">{{ $summary['renewal_expires_at'] }}</dd>
                        <p class="text-xs">Signed renewal revision {{ $summary['renewal_revision'] }} starts {{ $summary['renewal_starts_at'] }} UTC. The current signed term remains active until then; existing hall and staff limits continue to apply.</p>
                    </div>
                @endif
                @if (!empty($summary['grace_ends_at']))
                    <div class="rounded-lg border p-4">
                        <dt class="text-sm">Management grace ends (UTC)</dt>
                        <dd class="font-semibold">{{ $summary['grace_ends_at'] }}</dd>
                    </div>
                @endif
                <div class="rounded-lg border p-4">
                    <dt class="text-sm">Signed revision</dt>
                    <dd class="font-semibold">{{ $summary['revision'] ?? 'None' }}</dd>
                </div>
                <div class="rounded-lg border p-4">
                    <dt class="text-sm">Central check due</dt>
                    <dd class="font-semibold">{{ $summary['online_check_due'] ? 'Yes' : 'No' }}</dd>
                </div>
                <div class="rounded-lg border p-4">
                    <dt class="text-sm">Last authenticated HAFZATECH verification (UTC)</dt>
                    <dd class="font-semibold">{{ $summary['last_online_verified_at'] ?? 'Never verified online' }}</dd>
                </div>
                <div class="rounded-lg border p-4">
                    <dt class="text-sm">Next routine verification (UTC)</dt>
                    <dd class="font-semibold">{{ $summary['next_routine_check_at'] ?? ($summary['online_check_due'] ? 'Due now' : 'Not scheduled') }}</dd>
                </div>
                <div class="rounded-lg border p-4">
                    <dt class="text-sm">Most recent online attempt (UTC)</dt>
                    <dd class="font-semibold">{{ $summary['last_attempted_at'] ?? 'No attempt recorded' }}</dd>
                </div>
                @if ($summary['last_failure_category'])
                    <div class="rounded-lg border p-4">
                        <dt class="text-sm">Last verification issue</dt>
                        <dd class="font-semibold">{{ match ($summary['last_failure_category']) {
                            'network' => 'Central service connection unavailable',
                            'server' => 'Central service temporarily unavailable',
                            'invalid_response' => 'Central response could not be authenticated',
                            default => 'Verification unavailable',
                        } }}</dd>
                    </div>
                @endif
                @if ($summary['next_retry_at'])
                    <div class="rounded-lg border p-4">
                        <dt class="text-sm">Scheduled retry after (UTC)</dt>
                        <dd class="font-semibold">{{ $summary['next_retry_at'] }}</dd>
                    </div>
                @endif
            </dl>
            @if (!empty($summary['license_id']))
                <dl class="grid gap-3 sm:grid-cols-2" aria-label="Verified signed license identity">
                    <div class="rounded-lg border p-4">
                        <dt class="text-sm">License ID</dt>
                        <dd class="font-semibold break-all">{{ $summary['license_id'] }}</dd>
                    </div>
                    <div class="rounded-lg border p-4">
                        <dt class="text-sm">Edition</dt>
                        <dd class="font-semibold">{{ $summary['edition'] }}</dd>
                    </div>
                    <div class="rounded-lg border p-4">
                        <dt class="text-sm">Maximum halls</dt>
                        <dd class="font-semibold">{{ $summary['max_halls'] }}</dd>
                    </div>
                    <div class="rounded-lg border p-4">
                        <dt class="text-sm">Maximum staff</dt>
                        <dd class="font-semibold">{{ $summary['max_staff'] }}</dd>
                    </div>
                </dl>
            @endif
            <p class="text-xs">Local signature validation is not an online HAFZATECH check. Failed connections do not renew, revoke or erase an existing signed license. Normal online checks occur every 15 days; the Verify License button is a separate, owner-requested check.</p>
            @if ($summary['expiry_warning'])
                <p role="alert">Your signed license is within 15 days of expiration. Contact HAFZATECH about renewal.</p>
            @endif
            @if ($summary['state'] === 'feature_disabled')
                <p class="text-sm">Central licensing is not enabled for this installation. Existing venue workflows are unchanged.</p>
            @endif
            <p class="text-xs">Public-site policy: {{ $summary['public_policy'] }}. Dashboard policy: {{ $summary['dashboard_policy'] }}. These are informational until commercial enforcement is approved.</p>
            <p class="text-sm">Historical invoice statements remain available to authorized owners and administrators even if business operations are restricted after license expiry.</p>
            <a href="{{ \App\Filament\Pages\HistoricalInvoices::getUrl(['company'=>$companyId]) }}"
                class="inline-block rounded-lg border px-4 py-2 text-sm font-semibold">
                View past invoices and refunds
            </a>
            @if (config('central_control.support_enabled') === true)
                <a href="{{ \\App\\Filament\\Pages\\SupportInbox::getUrl(['company'=>$companyId]) }}"
                    class="inline-block rounded-lg border px-4 py-2 text-sm font-semibold">
                    Request renewal or contact HAFZATECH support
                </a>
                <p class="text-xs">A support request is not a payment confirmation or license renewal. Activation requires independently verified settlement and a signed revision.</p>
            @else
                <p class="text-sm">To renew, contact HAFZATECH using your existing support or billing contact. Online renewal requests are not yet enabled.</p>
            @endif
            <div class="flex flex-wrap gap-3">
                <button type="button" wire:click="refreshStatus" class="rounded-lg border px-4 py-2 text-sm">Refresh local status</button>
                @if (config('central_control.activation_enabled') === true && config('central_control.manual_checks_enabled') === true)
                    <button type="button" wire:click="verifyLicense" wire:loading.attr="disabled" wire:target="verifyLicense"
                        class="rounded-lg border px-4 py-2 text-sm">Verify License</button>
                @else
                    <button type="button" disabled aria-disabled="true" title="Requires approved HAFZATECH signed online license service"
                        class="rounded-lg border px-4 py-2 text-sm opacity-60">
                        Verify License (central service not activated)
                    </button>
                @endif
            </div>
            @if ($verificationMessage)
                <p role="status" class="text-sm" aria-live="polite">{{ $verificationMessage }}</p>
            @endif
        @endif
    </section>
</x-filament-panels::page>
