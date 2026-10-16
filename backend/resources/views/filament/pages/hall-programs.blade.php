<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Configure morning, noon and evening programs for each hall. Only an owner or administrator can change times or pricing.
            Saved rules are versioned and audited. No reservations are created here.
        </p>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block space-y-2">
                <span class="text-sm font-medium">Company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->companyOptions() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Hall</span>
                <select wire:model.live="hallId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->hallOptions() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if ($loadedHallId && $loadedHallId === $hallId && $loadedCompanyId === $companyId)
            <section class="space-y-3 rounded-xl border p-4" aria-label="Hall program status">
                <h2 class="text-lg font-semibold">Program schedule</h2>
                <p class="text-sm">Loaded revision: {{ $expectedRevision }}.
                    {{ $expectedRevision ? 'A saved configuration exists.' : 'Not configured. Complete all fields before saving.' }}
                </p>
                <p class="text-xs">Times are local 24-hour clock values, all programs must finish the same day. Setup and cleaning time cannot overlap another program. Grace does not grant automatic extension.</p>
                <button type="button" wire:click="reloadRules"
                    wire:confirm="Discard unsaved changes and reload the latest schedule?"
                    class="rounded-lg border px-3 py-2 text-sm">Reload latest rules</button>
            </section>

            @if ($this->canConfigureSelectedHall())
                <form wire:submit="saveRules" class="space-y-5" aria-label="Edit three daily hall programs">
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Currency code (three uppercase letters)</span>
                            <input type="text" maxlength="3" autocomplete="off" wire:model="currencyCode"
                                placeholder="e.g. PKR" class="w-full rounded-lg border p-3 text-gray-900" required>
                            @error('currencyCode') <span class="block text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>
                    <p class="text-xs">Prices are entered as integer currency minor units, not decimal amounts. Confirm the currency's minor-unit convention before using these settings for billing.</p>

                    <div class="grid gap-4 xl:grid-cols-3">
                        @foreach (['morning' => 'Morning', 'noon' => 'Noon', 'evening' => 'Evening'] as $program => $name)
                            <fieldset wire:key="hall-program-{{ $program }}" class="space-y-3 rounded-xl border p-4">
                                <legend class="px-1 text-base font-semibold">{{ $name }}</legend>
                                <div class="grid grid-cols-2 gap-3">
                                    @foreach (['start' => 'Start time', 'end' => 'End time'] as $field => $label)
                                        <label class="block space-y-1 text-sm">
                                            <span>{{ $label }}</span>
                                            <input type="time" wire:model="rules.{{ $program }}.{{ $field }}"
                                                class="w-full rounded-lg border p-2 text-gray-900" required>
                                            @error("rules.$program.$field") <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                        </label>
                                    @endforeach
                                </div>
                                @foreach ([
                                    'setupMinutes' => 'Setup buffer (minutes)',
                                    'cleaningMinutes' => 'Cleaning buffer (minutes)',
                                    'graceMinutes' => 'Free grace (minutes)',
                                    'overtimeIntervalMinutes' => 'Overtime interval (minutes)',
                                    'rentalRateMinor' => 'Rental rate (minor units)',
                                    'overtimeRateMinor' => 'Overtime rate (minor units per started interval)',
                                ] as $field => $label)
                                    <label class="block space-y-1 text-sm">
                                        <span>{{ $label }}</span>
                                        <input type="number" min="{{ $field === 'overtimeIntervalMinutes' ? 1 : 0 }}"
                                            step="1" wire:model.number="rules.{{ $program }}.{{ $field }}"
                                            class="w-full rounded-lg border p-2 text-gray-900" required>
                                        @error("rules.$program.$field") <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                    <label class="block space-y-2">
                        <span class="text-sm font-medium">Reason for this schedule or price change</span>
                        <textarea wire:model="reason" rows="2" maxlength="500" required
                            class="w-full rounded-lg border p-3 text-gray-900"
                            placeholder="Describe the approved change"></textarea>
                        @error('reason') <span class="block text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                    @error('rules') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-3 font-medium text-white">
                        Save program rules
                    </button>
                    <p class="text-xs">If someone updated this hall after you loaded it, the save is rejected. Reload before retrying.</p>
                </form>
            @else
                <section class="space-y-4 rounded-xl border p-4" aria-label="Read-only hall program rules">
                    <h2 class="font-semibold">Assigned hall programs (read-only)</h2>
                    <p class="text-sm">Only an owner or administrator may update these settings.</p>
                    @if ($expectedRevision)
                        <p>Currency: {{ $currencyCode }}</p>
                        <div class="grid gap-4 xl:grid-cols-3">
                            @foreach (['morning' => 'Morning', 'noon' => 'Noon', 'evening' => 'Evening'] as $program => $name)
                                <section class="rounded-lg border p-3">
                                    <h3 class="font-semibold">{{ $name }}</h3>
                                    <dl class="mt-2 space-y-1 text-sm">
                                        @foreach ([
                                            'start' => 'Start', 'end' => 'End',
                                            'setupMinutes' => 'Setup minutes', 'cleaningMinutes' => 'Cleaning minutes',
                                            'graceMinutes' => 'Grace minutes',
                                            'overtimeIntervalMinutes' => 'Overtime interval minutes',
                                            'rentalRateMinor' => 'Rental (minor units)',
                                            'overtimeRateMinor' => 'Overtime (minor units)',
                                        ] as $field => $label)
                                            <div><dt class="inline font-medium">{{ $label }}:</dt>
                                                <dd class="inline">{{ $rules[$program][$field] ?? '' }}</dd></div>
                                        @endforeach
                                    </dl>
                                </section>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        @else
            <p class="text-sm">No authorized hall is selected.</p>
        @endif
    </div>
</x-filament-panels::page>
