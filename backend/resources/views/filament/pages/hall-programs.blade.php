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

            @if ($expectedRevision > 0)
                <section class="space-y-3 rounded-xl border p-4" aria-label="Nonbinding overtime and turnover preview">
                    <h2 class="text-lg font-semibold">Grace and overtime safety preview</h2>
                    <p class="text-sm">A read-only same-day calculation from the saved hall rules, not unsaved form edits.
                        It is NOT actual event attendance, permission to extend, an invoice or a charge.
                        Any turnover conflict remains prohibited even if a projected overtime amount exists.</p>
                    <form wire:submit="calculateOvertimePreview" class="space-y-3">
                        <label class="block space-y-1 text-sm">
                            <span>Program to check</span>
                            <select wire:model="overtimeProgram" class="w-full rounded-lg border p-3 text-gray-900">
                                <option value="morning">Morning</option>
                                <option value="noon">Noon</option>
                                <option value="evening">Evening</option>
                            </select>
                        </label>
                        <label class="block space-y-1 text-sm">
                            <span>Simulated end time (same-day hall clock)</span>
                            <input type="time" wire:model="overtimeObservedEnd" class="w-full rounded-lg border p-3 text-gray-900">
                        </label>
                        @error('overtimeObservedEnd') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="rounded-lg border px-4 py-2 font-semibold">
                            Calculate nonbinding overtime safety preview
                        </button>
                    </form>
                    @if ($overtimePreview && $overtimePreview['companyId'] === $companyId && $overtimePreview['hallId'] === $hallId)
                        <div class="space-y-1 rounded-lg border p-3 text-sm">
                            <p>Saved rule revision {{ $overtimePreview['sourceRevision'] }}.
                                Scheduled end {{ $overtimePreview['scheduledEnd'] }},
                                simulated end {{ $overtimePreview['observedEnd'] }}.</p>
                            <p>Free grace {{ $overtimePreview['graceMinutes'] }} min,
                                observed overrun {{ $overtimePreview['overtimeMinutes'] }} min,
                                chargeable {{ $overtimePreview['chargeableMinutes'] }} min,
                                started billing intervals {{ $overtimePreview['startedIntervals'] }}.</p>
                            <p>Projected internal amount: {{ $overtimePreview['currency'] }}
                                {{ $overtimePreview['projectedAmountMinor'] }} minor units (NOT payable).</p>
                            <p>Maximum simulated safe end before turnover: {{ $overtimePreview['hardStopClock'] }}.</p>
                            <p class="font-semibold">
                                {{ $overtimePreview['conflictsWithTurnover']
                                    ? 'TURNOVER CONFLICT: Extension must NOT be authorized.'
                                    : 'No turnover conflict in this static simulation. Extension is still NOT authorized.' }}
                            </p>
                        </div>
                    @endif
                </section>
            @endif

            <section class="space-y-4 rounded-xl border p-4" aria-label="Hall seating and extra-unit pricing">
                <h2 class="text-lg font-semibold">Guest capacity, chairs and tables</h2>
                <p class="text-sm">Seating pricing revision: {{ $expectedSeatingRevision }}.
                    {{ $expectedSeatingRevision ? 'Approved configuration saved.' : 'Additional seating prices are not configured.' }}
                </p>
                <p class="text-xs">Included chairs and tables are covered by venue setup. Additional item rates are per unit, in integer currency minor units. Guest capacity is a maximum, never an authorization to overbook. These rules do not create a quotation or invoice.</p>
                @if ($this->canConfigureSelectedHall())
                    <form wire:submit="saveSeating" class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ([
                                'capacity' => 'Maximum guests',
                                'includedChairs' => 'Chairs included',
                                'includedTables' => 'Tables included',
                                'extraChairRateMinor' => 'Additional chair price (minor units)',
                                'extraTableRateMinor' => 'Additional table price (minor units)',
                            ] as $field => $label)
                                <label class="block space-y-2">
                                    <span class="text-sm font-medium">{{ $label }}</span>
                                    <input type="number" min="{{ $field === 'capacity' ? 1 : 0 }}" step="1"
                                        wire:model.number="seating.{{ $field }}"
                                        class="w-full rounded-lg border p-3 text-gray-900" required>
                                    @error("seating.$field") <span class="block text-sm text-red-600">{{ $message }}</span> @enderror
                                </label>
                            @endforeach
                            <label class="block space-y-2">
                                <span class="text-sm font-medium">Seating rate currency code</span>
                                <input type="text" maxlength="3" autocomplete="off" placeholder="e.g. PKR"
                                    wire:model="seatingCurrencyCode"
                                    class="w-full rounded-lg border p-3 text-gray-900" required>
                                @error('seatingCurrencyCode') <span class="block text-sm text-red-600">{{ $message }}</span> @enderror
                            </label>
                        </div>
                        <label class="block space-y-2">
                            <span class="text-sm font-medium">Reason for seating or rate change</span>
                            <textarea wire:model="seatingReason" rows="2" maxlength="500" required
                                class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                            @error('seatingReason') <span class="block text-sm text-red-600">{{ $message }}</span> @enderror
                        </label>
                        @error('seating') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-3 font-medium text-white">Save seating rules</button>
                        <p class="text-xs">Saved seating prices and times have independent revision histories. Reload if someone else has saved changes.</p>
                    </form>
                @else
                    <div class="space-y-2 text-sm">
                        <p>Assigned hall seating is read-only.</p>
                        @if ($expectedSeatingRevision)
                            <p>Currency: {{ $seatingCurrencyCode }}</p>
                            <dl class="grid gap-3 sm:grid-cols-2">
                                @foreach ([
                                    'capacity' => 'Maximum guests',
                                    'includedChairs' => 'Included chairs',
                                    'includedTables' => 'Included tables',
                                    'extraChairRateMinor' => 'Extra chair rate (minor units)',
                                    'extraTableRateMinor' => 'Extra table rate (minor units)',
                                ] as $field => $label)
                                    <div><dt class="font-medium">{{ $label }}</dt><dd>{{ $seating[$field] ?? '' }}</dd></div>
                                @endforeach
                            </dl>
                        @else
                            <p>Existing guest capacity: {{ $seating['capacity'] ?? '' }}. Approved additional seating prices are not available.</p>
                        @endif
                    </div>
                @endif
            </section>

            <section class="space-y-4 rounded-xl border p-4" aria-label="Hall optional services catalog">
                <h2 class="text-lg font-semibold">Catering and optional services</h2>
                <p class="text-sm">Catalog revision: {{ $expectedAddonRevision }}.
                    {{ $expectedAddonRevision ? 'An approved catalog exists.' : 'No approved add-on catalog yet.' }}
                </p>
                <p class="text-xs">Each service uses a chosen charge unit. Prices are integer currency minor units, not automatic booking or invoice charges. Exact package and billing rules require owner acceptance.</p>
                @if ($this->canConfigureSelectedHall())
                    <form wire:submit="saveAddons" class="space-y-4">
                        @foreach ($addons as $index => $addon)
                            <fieldset class="space-y-3 rounded-lg border p-3">
                                <legend class="px-1 font-medium">Optional service {{ $index + 1 }}</legend>
                                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    <label class="block space-y-1 text-sm">
                                        <span>Unique code</span>
                                        <input type="text" maxlength="40" wire:model="addons.{{ $index }}.code"
                                            placeholder="e.g. catering_standard" class="w-full rounded-lg border p-2 text-gray-900" required>
                                        @error("addons.$index.code") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block space-y-1 text-sm">
                                        <span>Service name</span>
                                        <input type="text" maxlength="120" wire:model="addons.{{ $index }}.name"
                                            class="w-full rounded-lg border p-2 text-gray-900" required>
                                        @error("addons.$index.name") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block space-y-1 text-sm">
                                        <span>Category</span>
                                        <select wire:model="addons.{{ $index }}.category" class="w-full rounded-lg border p-2 text-gray-900" required>
                                            <option value="">Choose category</option>
                                            @foreach (['catering' => 'Catering', 'music' => 'Music/DJ', 'lighting' => 'Lighting', 'bridal_entry' => 'Bride and groom entry', 'stage' => 'Stage', 'photography' => 'Photography', 'other' => 'Other'] as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error("addons.$index.category") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block space-y-1 text-sm">
                                        <span>Charge unit</span>
                                        <select wire:model="addons.{{ $index }}.chargeUnit" class="w-full rounded-lg border p-2 text-gray-900" required>
                                            <option value="">Choose charge unit</option>
                                            <option value="flat">Per event</option>
                                            <option value="per_guest">Per guest</option>
                                            <option value="per_unit">Per item</option>
                                        </select>
                                        @error("addons.$index.chargeUnit") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="block space-y-1 text-sm">
                                        <span>Rate (minor units)</span>
                                        <input type="number" min="0" step="1" wire:model.number="addons.{{ $index }}.rateMinor"
                                            class="w-full rounded-lg border p-2 text-gray-900" required>
                                        @error("addons.$index.rateMinor") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="addons.{{ $index }}.enabled">
                                        <span>Available for selection</span>
                                    </label>
                                </div>
                                <button type="button" wire:click="removeAddon({{ $index }})"
                                    class="rounded-lg border px-3 py-2 text-sm">Remove local item</button>
                            </fieldset>
                        @endforeach
                        <button type="button" wire:click="addAddon" class="rounded-lg border px-3 py-2 text-sm">Add optional service</button>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block space-y-1 text-sm">
                                <span>Catalog currency code</span>
                                <input type="text" maxlength="3" autocomplete="off" placeholder="e.g. PKR"
                                    wire:model="addonCurrencyCode" class="w-full rounded-lg border p-3 text-gray-900" required>
                                @error('addonCurrencyCode') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span>Change reason</span>
                                <textarea rows="2" maxlength="500" wire:model="addonReason"
                                    class="w-full rounded-lg border p-3 text-gray-900" required></textarea>
                                @error('addonReason') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </label>
                        </div>
                        @error('addons') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-3 font-medium text-white">Save optional services</button>
                        <p class="text-xs">A saved revision updates catalog settings only. Current bookings and issued contracts are never rewritten.</p>
                    </form>
                @else
                    <p class="text-sm">Assigned hall catalog is read-only.</p>
                    @if ($expectedAddonRevision)
                        <p class="text-sm">Currency: {{ $addonCurrencyCode }}</p>
                        <ul class="space-y-2">
                            @foreach ($addons as $addon)
                                <li class="rounded-lg border p-3 text-sm">
                                    <span class="font-semibold">{{ $addon['name'] ?? '' }}</span>
                                    ({{ $addon['category'] ?? '' }}, {{ $addon['chargeUnit'] ?? '' }})
                                    Rate: {{ $addon['rateMinor'] ?? '' }} minor units.
                                    {{ ($addon['enabled'] ?? false) ? 'Enabled' : 'Disabled' }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm">Optional service prices are not configured.</p>
                    @endif
                @endif
            </section>
        @else
            <p class="text-sm">No authorized hall is selected.</p>
        @endif
    </div>
</x-filament-panels::page>
