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
            @if ($summary['expiry_warning'])
                <p role="alert">Your signed license is within 15 days of expiration. Contact HAFZATECH about renewal.</p>
            @endif
            @if ($summary['state'] === 'feature_disabled')
                <p class="text-sm">Central licensing is not enabled for this installation. Existing venue workflows are unchanged.</p>
            @endif
            <p class="text-xs">Public-site policy: {{ $summary['public_policy'] }}. Dashboard policy: {{ $summary['dashboard_policy'] }}. These are informational until commercial enforcement is approved.</p>
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
