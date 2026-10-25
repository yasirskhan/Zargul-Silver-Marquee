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
                @foreach ([1 => 'Company and venue', 2 => 'Halls', 3 => 'Programs', 4 => 'Pricing', 5 => 'Add-ons', 6 => 'Review'] as $number => $label)
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
                            <fieldset wire:key="wizard-addons-{{ $hall['id'] }}" class="grid gap-3 rounded-lg border p-3 md:grid-cols-2">
                                <legend class="px-1 font-medium">{{ $hall['name'] }} add-ons</legend>
                                <label class="space-y-1 text-sm"><span>Code</span>
                                    <input wire:model="addons.{{ $hall['id'] }}.0.code" maxlength="40" required class="w-full rounded border p-2">
                                </label>
                                <label class="space-y-1 text-sm"><span>Name</span>
                                    <input wire:model="addons.{{ $hall['id'] }}.0.name" maxlength="120" required class="w-full rounded border p-2">
                                </label>
                                <label class="space-y-1 text-sm"><span>Category</span>
                                    <select wire:model="addons.{{ $hall['id'] }}.0.category" class="w-full rounded border p-2">
                                        @foreach (['catering' => 'Catering', 'music' => 'Music/DJ', 'lighting' => 'Lighting', 'bridal_entry' => 'Bridal entry', 'stage' => 'Stage', 'photography' => 'Photography', 'other' => 'Other'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="space-y-1 text-sm"><span>Charge unit</span>
                                    <select wire:model="addons.{{ $hall['id'] }}.0.chargeUnit" class="w-full rounded border p-2">
                                        <option value="flat">Per event</option>
                                        <option value="per_guest">Per guest</option>
                                        <option value="per_unit">Per item</option>
                                    </select>
                                </label>
                                <label class="space-y-1 text-sm"><span>Rate minor units</span>
                                    <input type="number" min="0" wire:model="addons.{{ $hall['id'] }}.0.rateMinor" required class="w-full rounded border p-2">
                                </label>
                            </fieldset>
                        @endforeach
                    </section>
                @else
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
                    @if ($step < 6)
                        <button type="submit" class="rounded-lg bg-emerald-800 px-4 py-2 font-semibold text-white">Save and continue</button>
                    @else
                        <p class="text-xs">Final completion requires every approved stage: branding, payments, staff and licensing. This review does not complete the installation.</p>
                    @endif
                </div>
            </form>
        @endif
    </div>
</x-filament-panels::page>
