<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Publish exact guest-count price brackets for each hall. Every save is a new immutable revision.
            No booking is created here and no public price is active until saved by an authorized Owner/Admin.
        </p>
        <div class="grid gap-4 md:grid-cols-3">
            <label class="block space-y-2"><span class="text-sm font-medium">Company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->companyOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="block space-y-2"><span class="text-sm font-medium">Hall</span>
                <select wire:model.live="hallId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->hallOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="block space-y-2"><span class="text-sm font-medium">ISO currency</span>
                <input wire:model="currencyCode" maxlength="3" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
        </div>

        @if ($this->hallOptions() === [])
            <p role="alert" class="rounded border border-amber-400 p-3">Create an active hall before configuring guest pricing.</p>
        @else
            <section class="space-y-3" aria-label="Exact guest price brackets">
                <h2 class="text-lg font-semibold">Exact guest price brackets</h2>
                @error('tiers') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @foreach ($tiers as $index => $tier)
                    <div class="grid items-end gap-3 rounded-lg border p-3 md:grid-cols-4" wire:key="tier-{{ $index }}">
                        <label class="block space-y-1"><span class="text-xs">Minimum guests</span>
                            <input type="number" min="1" wire:model="tiers.{{ $index }}.minGuests" class="w-full rounded border p-2 text-gray-900">
                        </label>
                        <label class="block space-y-1"><span class="text-xs">Maximum guests</span>
                            <input type="number" min="1" wire:model="tiers.{{ $index }}.maxGuests" class="w-full rounded border p-2 text-gray-900">
                        </label>
                        <label class="block space-y-1"><span class="text-xs">Price (minor units)</span>
                            <input type="number" min="0" wire:model="tiers.{{ $index }}.priceMinor" class="w-full rounded border p-2 text-gray-900">
                        </label>
                        <button type="button" wire:click="removeTier({{ $index }})" class="rounded border px-3 py-2">Remove</button>
                    </div>
                @endforeach
                <button type="button" wire:click="addTier" class="rounded border px-3 py-2">Add bracket</button>
            </section>
            <label class="block space-y-2"><span class="text-sm font-medium">Reason for this revision (required)</span>
                <textarea wire:model="reason" rows="2" maxlength="500" class="w-full rounded-lg border p-3 text-gray-900"></textarea>
            </label>
            <button type="button" wire:click="save" class="rounded-lg bg-primary-600 px-4 py-2 font-semibold text-white">
                Publish guest pricing revision
            </button>
        @endif
    </div>
</x-filament-panels::page>