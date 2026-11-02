<x-filament-panels::page>
    <div class="space-y-6">
        <x-venuedesk.header eyebrow="VenueDesk · Website" title="Hall Photography">
            Choose the approved images for each hall. Published images appear only after the website is republished.
        </x-venuedesk.header>
        @if ($message !== '')
            <p role="status" class="rounded border p-3 text-sm">{{ $message }}</p>
        @endif
        <div class="grid gap-4 md:grid-cols-2">
            <label class="space-y-2"><span class="text-sm font-medium">Company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->companyOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <label class="space-y-2"><span class="text-sm font-medium">Hall</span>
                <select wire:model.live="hallId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->hallOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
        </div>
        <section class="space-y-3 rounded-xl border p-4" aria-label="Approved hall photographs">
            <h2 class="text-lg font-semibold">Approved media</h2>
            <p class="text-sm">Only approved images from this company can be selected. The first selected image is the primary hall photograph.</p>
            @forelse ($this->media() as $asset)
                <label class="flex items-center gap-3 rounded border p-3">
                    <input type="checkbox" value="{{ $asset['id'] }}" wire:model="selectedMediaIds">
                    <span>{{ $asset['alt'] }}</span>
                    <code class="text-xs">{{ $asset['id'] }}</code>
                </label>
            @empty
                <p class="text-sm">Approve images in Website → Media before assigning hall photography.</p>
            @endforelse
            <label class="block space-y-1"><span class="text-sm font-medium">Internal reason</span>
                <input wire:model="reason" maxlength="500" class="w-full rounded border p-3">
                @error('reason') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
            </label>
            <button type="button" wire:click="save" class="rounded-lg bg-emerald-800 px-4 py-2 font-semibold text-white">Save hall photography</button>
        </section>
    </div>
</x-filament-panels::page>