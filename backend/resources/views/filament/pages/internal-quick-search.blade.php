<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm">Private role-scoped reference search. Staff see assigned-hall planning only;
            customer contacts and financial transactions are never exposed or performed here.</p>
        <label class="block space-y-1">
            <span class="text-sm font-medium">Company</span>
            <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                @foreach ($this->companyOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <form wire:submit="runSearch" class="space-y-3 rounded-xl border p-4">
            <label class="block space-y-1">
                <span class="text-sm font-medium">Search permitted planning tasks or owner/admin internal records</span>
                <input type="search" wire:model="searchTerm" minlength="3" maxlength="80"
                    class="w-full rounded-lg border p-3 text-gray-900"
                    placeholder="Reference, date, program or task">
            </label>
            @error('searchTerm') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <label class="block space-y-1">
                <span class="text-sm font-medium">Record type</span>
                <select wire:model="searchKind" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->sourceOptions() as $kind => $label)
                        <option value="{{ $kind }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            @error('searchKind') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button type="submit" wire:loading.attr="disabled"
                class="rounded-lg border px-4 py-2 font-semibold">Search company records</button>
        </form>
        @if ($loadedCompanyId === $companyId)
            <section aria-label="Authorized internal search results" class="space-y-3">
                <h2 class="font-semibold">Internal search results</h2>
                @forelse ($results as $entry)
                    <article wire:key="internal-search-{{ $entry['kind'] }}-{{ $entry['id'] }}"
                        class="space-y-1 rounded-xl border p-4 text-sm">
                        <strong>{{ ucfirst($entry['kind']) }}: {{ $entry['label'] }}</strong>
                        <p>Status: {{ $entry['status'] }}</p>
                        <p class="break-all text-xs">Reference {{ $entry['id'] }}</p>
                        <a href="{{ $entry['path'] }}" class="underline">Open {{ $entry['kind'] }} workspace</a>
                    </article>
                @empty
                    <p class="text-sm">No authorized results for this search.</p>
                @endforelse
            </section>
        @endif
    </div>
</x-filament-panels::page>
