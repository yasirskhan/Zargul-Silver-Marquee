<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Owner/admin-only, read-only company audit history. Filters match exact identifiers.
            Some entries can contain sensitive business information. Encrypted owner export is available;
            secure off-site storage, key custody and restore approval remain external.
        </p>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <label class="block space-y-2">
                <span class="text-sm font-medium">Company</span>
                <select wire:model.live="companyId" class="w-full rounded-lg border p-3 text-gray-900">
                    @foreach ($this->companyOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Action</span>
                <input wire:model="filters.action" type="text" maxlength="120" placeholder="e.g. hall.seating_updated" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Entity type</span>
                <input wire:model="filters.entityType" type="text" maxlength="64" placeholder="e.g. hall" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">Entity ID</span>
                <input wire:model="filters.entityId" type="text" maxlength="80" placeholder="Exact identifier" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">From date</span>
                <input wire:model="filters.fromDate" type="date" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
            <label class="block space-y-2">
                <span class="text-sm font-medium">To date</span>
                <input wire:model="filters.toDate" type="date" class="w-full rounded-lg border p-3 text-gray-900">
            </label>
        </div>
        <div>
            <button type="button" wire:click="applyFilters" class="rounded-lg border px-4 py-2 text-sm font-semibold">
                Search / refresh
            </button>
            @error('filters') <p role="alert" class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <section aria-label="Encrypted audit export" class="space-y-3 rounded-xl border p-4">
            <h2 class="font-semibold">Encrypted audit export</h2>
            <p class="text-sm">
                Downloads ciphertext only, encrypted with the application key. It contains no plaintext in the browser,
                URL or audit log. Use an approved secure store and verify the archive before any recovery.
            </p>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="block space-y-1">
                    <span class="text-sm font-medium">From date</span>
                    <input type="date" wire:model="exportFromDate" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                <label class="block space-y-1">
                    <span class="text-sm font-medium">To date</span>
                    <input type="date" wire:model="exportToDate" class="w-full rounded-lg border p-3 text-gray-900">
                </label>
                <label class="block space-y-1">
                    <span class="text-sm font-medium">Export reason</span>
                    <input type="text" wire:model="exportReason" maxlength="300"
                        placeholder="Why is this protected archive needed?"
                        class="w-full rounded-lg border p-3 text-gray-900">
                </label>
            </div>
            @error('exportFromDate') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            @error('exportToDate') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            @error('exportReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button type="button" wire:click="downloadEncryptedExport"
                class="rounded-lg bg-emerald-800 px-4 py-2 text-sm font-semibold text-white">
                Prepare encrypted audit export
            </button>
            <p class="text-xs">Maximum window: 31 days. The export is idempotent per request and audited.</p>
        </section>

        @if ($loadedCompanyId === $companyId)
            <section aria-label="Company audit results" class="space-y-4">
                <p class="text-sm">
                    Showing {{ count($entries) }} record(s) from position {{ $offset + 1 }}.
                    New events may change pagination order; refresh before relying on a historical search.
                </p>
                @forelse ($entries as $entry)
                    <article wire:key="audit-{{ $entry['id'] }}" class="space-y-2 rounded-xl border p-4">
                        <div class="flex flex-wrap justify-between gap-2">
                            <h2 class="break-all font-semibold">{{ $entry['action'] }}</h2>
                            <time class="text-sm" datetime="{{ $entry['createdAt'] }}">{{ $entry['createdAt'] }}</time>
                        </div>
                        <p class="break-all text-sm">
                            {{ $entry['entityType'] }} / {{ $entry['entityId'] }}
                            | Actor ID: {{ $entry['actorId'] ?? 'system' }}
                        </p>
                        <details class="text-sm">
                            <summary class="cursor-pointer font-medium">Before / after / context</summary>
                            <div class="mt-3 grid gap-3 lg:grid-cols-3">
                                <div class="min-w-0">
                                    <h3 class="font-medium">Before</h3>
                                    <pre class="overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-gray-100 p-3 text-xs text-gray-900">{{ json_encode($entry['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="font-medium">After</h3>
                                    <pre class="overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-gray-100 p-3 text-xs text-gray-900">{{ json_encode($entry['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="font-medium">Context / reason</h3>
                                    <pre class="overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-gray-100 p-3 text-xs text-gray-900">{{ json_encode($entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            </div>
                        </details>
                    </article>
                @empty
                    <p class="rounded-lg border p-4 text-sm">No matching audit records for this company.</p>
                @endforelse
                <nav aria-label="Audit result pagination" class="flex items-center gap-3">
                    <button type="button" wire:click="previousPage" @disabled($offset === 0) class="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Previous</button>
                    <button type="button" wire:click="nextPage" @disabled(! $hasMore || $offset > 5000 - 25) class="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">Next</button>
                </nav>
            </section>
        @endif
    </div>
</x-filament-panels::page>
