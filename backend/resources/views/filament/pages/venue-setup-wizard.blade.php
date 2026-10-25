<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Only an active, verified company Owner can complete this setup. Each step is saved to PostgreSQL and can
            be resumed without resetting existing halls or company data.
        </p>

        <label class="block max-w-sm space-y-1">
            <span class="text-sm font-medium">Operating company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        @if ($status === 'completed')
            <section aria-label="Installation setup completed" class="rounded-xl border p-4">
                <h2 class="font-semibold">Venue setup completed</h2>
                <p class="text-sm">Completed {{ $completedAt }}. This setup cannot be re-entered or destructively reset from this page.</p>
                <p class="text-xs">Revision {{ $revision }}</p>
                <a href="{{ \App\Filament\Pages\HallPrograms::getUrl(['company' => $companyId]) }}"
                    class="inline-flex rounded-lg border px-4 py-2">Open hall configuration</a>
            </section>
        @else
            <ol class="flex flex-wrap gap-2 text-sm" aria-label="Setup steps">
                @foreach ([1 => 'Company and venue', 2 => 'Halls', 3 => 'Programs', 4 => 'Pricing', 5 => 'Add-ons', 6 => 'Branding', 7 => 'Payments', 8 => 'Staff', 9 => 'Licensing', 10 => 'Review'] as $number => $label)
                    <li class="rounded-lg border px-3 py-2 {{ $step === $number ? 'font-semibold' : '' }}">
                        {{ $number }}. {{ $label }}
                    </li>
                @endforeach
            </ol>

            <form wire:submit="saveAndContinue" class="space-y-4">
                @if ($step === 1)
                    <section aria-label="Company and venue details" class="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
                        <label class="block space-y-1"><span>Legal venue name</span>
                            <input wire:model="company.legalName" maxlength="160" required class="w-full rounded-lg border p-3">
                            @error('company.legalName') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block space-y-1"><span>Contact email</span>
                            <input type="email" wire:model="company.contactEmail" maxlength="254" class="w-full rounded-lg border p-3">
                            @error('company.contactEmail') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block space-y-1"><span>Contact phone</span>
                            <input wire:model="company.contactPhone" maxlength="32" class="w-full rounded-lg border p-3">
                        </label>
                        <label class="block space-y-1"><span>ISO operating currency</span>
                            <input wire:model="company.currency" maxlength="3" required class="w-full rounded-lg border p-3" placeholder="PKR">
                            @error('company.currency') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block space-y-1 md:col-span-2"><span>Venue address</span>
                            <input wire:model="company.address" maxlength="240" class="w-full rounded-lg border p-3">
                        </label>
                        <label class="block space-y-1 md:col-span-2"><span>IANA timezone</span>
                            <select wire:model="company.timezone" class="w-full rounded-lg border p-3">
                                @foreach ($this->timezones() as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </select>
                            @error('company.timezone') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </section>
                @elseif ($step === 2)
                    <section aria-label="Halls" class="space-y-4 rounded-xl border p-4">
                        @foreach ($halls as $index => $hall)
                            <fieldset wire:key="wizard-hall-{{ $index }}" class="space-y-3 rounded-lg border p-3">
                                <legend class="px-1 font-medium">Hall {{ $index + 1 }}</legend>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <label class="block space-y-1"><span>Hall name</span>
                                        <input wire:model="halls.{{ $index }}.name" maxlength="120" required class="w-full rounded-lg border p-3">
                                        @error("halls.$index.name") <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block space-y-1"><span>Capacity</span>
                                        <input type="number" min="1" max="100000" wire:model="halls.{{ $index }}.capacity" required class="w-full rounded-lg border p-3">
                                        @error("halls.$index.capacity") <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block space-y-1"><span>Hall address</span>
                                        <input wire:model="halls.{{ $index }}.address" maxlength="240" class="w-full rounded-lg border p-3">
                                    </label>
                                    <label class="block space-y-1"><span>Hall phone</span>
                                        <input wire:model="halls.{{ $index }}.phone" maxlength="32" class="w-full rounded-lg border p-3">
                                    </label>
                                </div>
                                @if (count($halls) > 1)
                                    <button type="button" wire:click="removeHall({{ $index }})" class="rounded-lg border px-3 py-2 text-sm">Remove hall</button>
                                @endif
                            </fieldset>
                        @endforeach
                        <button type="button" wire:click="addHall" class="rounded-lg border px-3 py-2 text-sm">Add another hall</button>
                    </section>
                @elseif ($step === 3)
                    <section aria-label="Hall programs" class="space-y-4 rounded-xl border p-4">
                        @foreach ($halls as $hall)
                            <fieldset wire:key="wizard-programs-{{ $hall['id'] }}" class="space-y-3 rounded-lg border p-3">
                                <legend class="px-1 font-medium">{{ $hall['name'] }} programs</legend>
                                @foreach (['morning' => 'Morning', 'noon' => 'Noon', 'evening' => 'Evening'] as $program => $label)
                                    <div class="grid gap-2 md:grid-cols-3">
                                        <p class="font-medium">{{ $label }}</p>
                                        <label class="space-y-1 text-sm"><span>Start</span>
                                            <input type="time" wire:model="programs.{{ $hall['id'] }}.{{ $program }}.start" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="space-y-1 text-sm"><span>End</span>
                                            <input type="time" wire:model="programs.{{ $hall['id'] }}.{{ $program }}.end" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Setup min</span>
                                            <input type="number" min="0" wire:model="programs.{{ $hall['id'] }}.{{ $program }}.setupMinutes" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Cleaning min</span>
                                            <input type="number" min="0" wire:model="programs.{{ $hall['id'] }}.{{ $program }}.cleaningMinutes" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Grace min</span>
                                            <input type="number" min="0" max="120" wire:model="programs.{{ $hall['id'] }}.{{ $program }}.graceMinutes" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Overtime interval</span>
                                            <input type="number" min="1" max="120" wire:model="programs.{{ $hall['id'] }}.{{ $program }}.overtimeIntervalMinutes" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Rental minor units</span>
                                            <input type="number" min="0" wire:model="programs.{{ $hall['id'] }}.{{ $program }}.rentalRateMinor" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Overtime minor units</span>
                                            <input type="number" min="0" wire:model="programs.{{ $hall['id'] }}.{{ $program }}.overtimeRateMinor" required class="w-full rounded border p-2">
                                        </label>
                                    </div>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </section>
                @elseif ($step === 4)
                    <section aria-label="Seating and guest pricing" class="space-y-4 rounded-xl border p-4">
                        @foreach ($halls as $hall)
                            <fieldset wire:key="wizard-pricing-{{ $hall['id'] }}" class="grid gap-3 rounded-lg border p-3 md:grid-cols-2">
                                <legend class="px-1 font-medium">{{ $hall['name'] }} seating</legend>
                                @foreach (['capacity' => 'Capacity', 'includedChairs' => 'Included chairs', 'includedTables' => 'Included tables', 'extraChairRateMinor' => 'Extra chair minor units', 'extraTableRateMinor' => 'Extra table minor units'] as $field => $label)
                                    <label class="space-y-1 text-sm"><span>{{ $label }}</span>
                                        <input type="number" min="0" wire:model="pricing.{{ $hall['id'] }}.{{ $field }}" required class="w-full rounded border p-2">
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </section>
                @elseif ($step === 5)
                    <section aria-label="Add-ons" class="space-y-4 rounded-xl border p-4">
                        @foreach ($halls as $hall)
                            <fieldset wire:key="wizard-addons-{{ $hall['id'] }}" class="space-y-3 rounded-lg border p-3">
                                <legend class="px-1 font-medium">{{ $hall['name'] }} add-ons</legend>
                                @forelse (($addons[$hall['id']] ?? []) as $index => $item)
                                    <div wire:key="wizard-addon-{{ $hall['id'] }}-{{ $index }}" class="grid gap-2 rounded border p-2 md:grid-cols-2">
                                        <label class="space-y-1 text-sm"><span>Code</span>
                                            <input wire:model="addons.{{ $hall['id'] }}.{{ $index }}.code" maxlength="40" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Name</span>
                                            <input wire:model="addons.{{ $hall['id'] }}.{{ $index }}.name" maxlength="120" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Category</span>
                                            <select wire:model="addons.{{ $hall['id'] }}.{{ $index }}.category" class="w-full rounded border p-2">
                                                @foreach (['catering' => 'Catering', 'music' => 'Music/DJ', 'lighting' => 'Lighting', 'bridal_entry' => 'Bridal entry', 'stage' => 'Stage', 'photography' => 'Photography', 'other' => 'Other'] as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Charge unit</span>
                                            <select wire:model="addons.{{ $hall['id'] }}.{{ $index }}.chargeUnit" class="w-full rounded border p-2">
                                                <option value="flat">Per event</option>
                                                <option value="per_guest">Per guest</option>
                                                <option value="per_unit">Per item</option>
                                            </select>
                                        </label>
                                        <label class="space-y-1 text-sm"><span>Rate minor units</span>
                                            <input type="number" min="0" wire:model="addons.{{ $hall['id'] }}.{{ $index }}.rateMinor" required class="w-full rounded border p-2">
                                        </label>
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="checkbox" wire:model="addons.{{ $hall['id'] }}.{{ $index }}.enabled"> Available
                                        </label>
                                        <button type="button" wire:click="removeAddon('{{ $hall['id'] }}', {{ $index }})" class="rounded border px-3 py-2 text-sm">Remove</button>
                                    </div>
                                @empty
                                    <p class="text-sm">No optional add-ons configured for this hall.</p>
                                @endforelse
                                <button type="button" wire:click="addAddon('{{ $hall['id'] }}')" class="rounded border px-3 py-2 text-sm">Add optional service</button>
                            </fieldset>
                        @endforeach
                    </section>
                @elseif ($step === 6)
                    <section aria-label="Website branding" class="grid gap-3 rounded-xl border p-4 md:grid-cols-2">
                        <h2 class="font-semibold md:col-span-2">Website branding</h2>
                        <label class="space-y-1 text-sm"><span>Website display name</span>
                            <input wire:model="branding.siteName" maxlength="120" required class="w-full rounded border p-3">
                        </label>
                        <label class="space-y-1 text-sm"><span>Tagline</span>
                            <input wire:model="branding.tagline" maxlength="240" class="w-full rounded border p-3">
                        </label>
                        <label class="space-y-1 text-sm"><span>Approved theme</span>
                            <select wire:model="branding.themePreset" class="w-full rounded border p-3">
                                @foreach (['silver' => 'Silver', 'ivory' => 'Ivory', 'midnight' => 'Midnight', 'sage' => 'Sage'] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="space-y-1 text-sm"><span>Typography</span>
                            <select wire:model="branding.fontPreset" class="w-full rounded border p-3">
                                <option value="classic">Classic</option><option value="modern">Modern</option>
                            </select>
                        </label>
                        <label class="space-y-1 text-sm"><span>Footer message</span>
                            <input wire:model="branding.footerText" maxlength="240" class="w-full rounded border p-3">
                        </label>
                        <label class="space-y-1 text-sm"><span>Search description</span>
                            <input wire:model="branding.seoDescription" maxlength="160" class="w-full rounded border p-3">
                        </label>
                        <p class="text-xs md:col-span-2">Saves an authorized branding draft only. Public publication remains a separate publisher action and is not enabled by the wizard.</p>
                    </section>
                @elseif ($step === 7)
                    <section aria-label="Tenant payment settings" class="space-y-3 rounded-xl border p-4">
                        <h2 class="font-semibold">Venue-owned payment instructions</h2>
                        <p class="text-sm">These are the venue's customer-facing payment instructions, separate from HAFZATECH subscription billing. No live provider or payment activation occurs.</p>
                        <div class="space-y-2">
                            <h3 class="font-medium">Venue bank accounts</h3>
                            @forelse (($payments['banks'] ?? []) as $index => $bank)
                                <div wire:key="payment-bank-{{ $index }}" class="grid gap-2 rounded border p-2 md:grid-cols-3">
                                    <label class="space-y-1 text-sm"><span>Bank name</span>
                                        <input wire:model="payments.banks.{{ $index }}.bankName" maxlength="120" class="w-full rounded border p-2">
                                    </label>
                                    <label class="space-y-1 text-sm"><span>Branch</span>
                                        <input wire:model="payments.banks.{{ $index }}.branch" maxlength="160" class="w-full rounded border p-2">
                                    </label>
                                    <label class="space-y-1 text-sm"><span>Masked account (e.g. ****1234)</span>
                                        <input wire:model="payments.banks.{{ $index }}.accountMasked" maxlength="32" class="w-full rounded border p-2">
                                    </label>
                                    <button type="button" wire:click="removeBank({{ $index }})" class="rounded border px-3 py-2 text-sm md:col-span-3">Remove bank</button>
                                </div>
                            @empty
                                <p class="text-sm">No venue bank account configured.</p>
                            @endforelse
                            <button type="button" wire:click="addBank" class="rounded border px-3 py-2 text-sm">Add bank account</button>
                        </div>
                        <label class="space-y-1 text-sm"><span>Manual instructions</span>
                            <textarea wire:model="payments.instructions" rows="3" maxlength="1000" class="w-full rounded border p-3"></textarea>
                        </label>
                        <label class="space-y-1 text-sm"><span>QR / wallet instructions</span>
                            <textarea wire:model="payments.qrInstructions" rows="2" maxlength="1000" class="w-full rounded border p-3"></textarea>
                        </label>
                        <label class="space-y-1 text-sm"><span>Approved deposit percentage (5% where applicable)</span>
                            <input type="number" min="0" max="100" wire:model="payments.depositPercent" class="w-full rounded border p-3">
                        </label>
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-medium">Permitted manual methods</legend>
                            @foreach (['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'qr' => 'QR instructions', 'easypaisa' => 'Easypaisa', 'jazzcash' => 'JazzCash', 'raast' => 'Raast'] as $value => $label)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="payments.methods" value="{{ $value }}"> {{ $label }}
                                </label>
                            @endforeach
                        </fieldset>
                        <p class="text-xs">Provider API activation and raw credentials are unavailable in setup. QR screenshots and transaction references are never treated as verified settlement.</p>
                    </section>
                @elseif ($step === 8)
                    <section aria-label="Staff invitations" class="space-y-4 rounded-xl border p-4">
                        <h2 class="font-semibold">Initial staff</h2>
                        <p class="text-sm">Invitations do not grant access. Staff must accept, verify email and enrol in MFA before privileged use. Owner-only venues may skip additional staff explicitly.</p>
                        <label class="block max-w-sm space-y-1 text-sm"><span>Staff setup</span>
                            <select wire:model="staffMode" class="w-full rounded border p-2">
                                <option value="">Choose explicitly</option>
                                <option value="owner_only">Owner-operated venue (no additional staff)</option>
                                <option value="invite">Invite staff below</option>
                            </select>
                        </label>
                        <div class="grid gap-3 md:grid-cols-3">
                            <label class="space-y-1 text-sm"><span>Staff email</span>
                                <input type="email" wire:model="staffEmail" maxlength="254" class="w-full rounded border p-2">
                            </label>
                            <label class="space-y-1 text-sm"><span>Role</span>
                                <select wire:model="staffRole" class="w-full rounded border p-2">
                                    <option value="admin">Admin</option>
                                    <option value="manager">Manager</option>
                                    <option value="reception">Reception</option>
                                    <option value="operations">Operations</option>
                                </select>
                            </label>
                            <label class="space-y-1 text-sm"><span>Invitation reason</span>
                                <input wire:model="staffReason" maxlength="300" class="w-full rounded border p-2">
                            </label>
                        </div>
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-medium">Assign halls (where applicable)</legend>
                            @foreach ($halls as $hall)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="staffHallIds" value="{{ $hall['id'] }}"> {{ $hall['name'] }}
                                </label>
                            @endforeach
                        </fieldset>
                        <button type="button" wire:click="inviteStaff" class="rounded border px-3 py-2 text-sm">Create invitation</button>
                        @error('staffEmail') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        @error('staffReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <div class="space-y-2">
                            @forelse ($staffInvitations as $invite)
                                <div class="flex flex-wrap justify-between gap-2 rounded border p-2 text-sm">
                                    <span>{{ $invite['email'] }} · {{ $invite['role'] }} · {{ strtoupper($invite['status']) }}</span>
                                    @if ($invite['status'] !== 'revoked')
                                        <button type="button" wire:click="revokeStaff('{{ $invite['id'] }}')" class="rounded border px-3 py-1">Revoke</button>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm">No staff invitations yet.</p>
                            @endforelse
                        </div>
                    </section>
                @elseif ($step === 9)
                    <section aria-label="Installation licensing" class="space-y-3 rounded-xl border p-4">
                        <h2 class="font-semibold">HAFZATECH installation license</h2>
                        <p class="text-sm">A pending registration, payment reference or screenshot is not a verified license. Only a locally verified signed grant can complete licensing readiness.</p>
                        <dl class="grid gap-2 text-sm md:grid-cols-2">
                            <div><dt class="font-medium">Status</dt><dd>{{ $licenseSummary['state'] ?? 'unavailable' }}</dd></div>
                            <div><dt class="font-medium">License ID</dt><dd>{{ $licenseSummary['license_id'] ?? 'not issued' }}</dd></div>
                            <div><dt class="font-medium">Edition</dt><dd>{{ $licenseSummary['edition'] ?? 'not available' }}</dd></div>
                            <div><dt class="font-medium">Expires</dt><dd>{{ $licenseSummary['expires_at'] ?? 'not available' }}</dd></div>
                            <div><dt class="font-medium">Grace ends</dt><dd>{{ $licenseSummary['grace_ends_at'] ?? 'not available' }}</dd></div>
                            <div><dt class="font-medium">Last online verification</dt><dd>{{ $licenseSummary['last_online_verified_at'] ?? 'never' }}</dd></div>
                            <div><dt class="font-medium">Next automatic check</dt><dd>{{ $licenseSummary['next_routine_check_at'] ?? 'not scheduled' }}</dd></div>
                            <div><dt class="font-medium">Next retry</dt><dd>{{ $licenseSummary['next_retry_at'] ?? 'none' }}</dd></div>
                        </dl>
                        @if (($licenseSummary['state'] ?? '') === 'feature_disabled')
                            <p class="text-sm">Central licensing is not released on this installation. Setup can be saved, but final completion remains blocked.</p>
                        @endif
                        @if ($licenseMessage) <p role="status" class="text-sm">{{ $licenseMessage }}</p> @endif
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="verifyWizardLicense" class="rounded border px-3 py-2 text-sm">Verify License</button>
                            <label class="flex items-center gap-2 text-sm"><span>Renewal term</span>
                                <select wire:model="requestedTermMonths" class="rounded border p-2">
                                    <option value="6">Six months</option>
                                    <option value="12">Twelve months</option>
                                </select>
                            </label>
                            <button type="button" wire:click="requestWizardRenewal" class="rounded border px-3 py-2 text-sm">Request renewal review</button>
                        </div>
                        <p class="text-xs">No live payment, signature or production activation occurs here. Expiry warnings begin 15 days before signed expiry; backend grace is seven days.</p>
                    </section>
                @else
                    <section aria-label="Review installation details" class="space-y-3 rounded-xl border p-4">
                        <h2 class="font-semibold">Review</h2>
                        <p><strong>Venue:</strong> {{ $company['legalName'] }}</p>
                        <p><strong>Contact:</strong> {{ $company['contactEmail'] ?: 'not provided' }} · {{ $company['contactPhone'] ?: 'not provided' }}</p>
                        <p><strong>Address:</strong> {{ $company['address'] ?: 'not provided' }}</p>
                        <p><strong>Currency/timezone:</strong> {{ $company['currency'] }} · {{ $company['timezone'] }}</p>
                        <ul class="space-y-2">
                            @foreach ($halls as $hall)
                                <li class="rounded-lg border p-3">
                                    <strong>{{ $hall['name'] }}</strong> · capacity {{ $hall['capacity'] }}
                                    <br>{{ $hall['address'] ?: 'No address' }} · {{ $hall['phone'] ?: 'No phone' }}
                                </li>
                            @endforeach
                        </ul>
                        <p class="text-xs">Saved company, halls, program and pricing revisions are operational immediately. Final completion remains blocked until branding, payments, staff and licensing stages exist.</p>
                    </section>
                @endif

                @error('wizard') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="flex flex-wrap gap-3">
                    @if ($step > 1)
                        <button type="button" wire:click="back" class="rounded-lg border px-4 py-2">Back</button>
                    @endif
                    @if ($step < 10)
                        <button type="submit" class="rounded-lg bg-emerald-800 px-4 py-2 font-semibold text-white">Save and continue</button>
                    @else
                        <p class="text-xs">Final completion requires every approved stage: branding, payments, staff and licensing. This review does not complete the installation.</p>
                    @endif
                </div>
            </form>
        @endif
    </div>
</x-filament-panels::page>
