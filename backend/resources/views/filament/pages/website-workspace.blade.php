<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Select your venue and page to review its draft and published status. Editing and release tools are being connected securely.
        </p>
        <div class="grid gap-4 md:grid-cols-2">
            <label class="block space-y-2">
                <span class="text-sm font-medium">Venue company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->companyOptions() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Website page</span>
                <select wire:model.live="pageId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->pageOptions() as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        @if ($page = $this->currentPage())
            <section class="rounded-xl border p-5" aria-label="Website page status">
                <h2 class="text-lg font-semibold">{{ $page->title }}</h2>
                <p class="mt-2 text-sm">Path: {{ $page->slug }}</p>
                <p class="mt-2 text-sm">Latest draft: revision {{ $page->draft_revision }}</p>
                <p class="mt-2 text-sm">
                    Published: {{ $page->published_revision_id ? 'A published snapshot exists' : 'Not published' }}
                </p>
            </section>
        @else
            <p role="status">No accessible page is selected.</p>
        @endif
    </div>
</x-filament-panels::page>
