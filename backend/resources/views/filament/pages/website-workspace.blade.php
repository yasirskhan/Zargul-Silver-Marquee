<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Edit typed website sections, then save a draft for review. Only publishing changes the approved public snapshot.
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
            <section class="space-y-2 rounded-xl border p-5" aria-label="Website page status">
                <h2 class="text-lg font-semibold">{{ $page->title }}</h2>
                <p class="text-sm">Path: {{ $page->slug }}</p>
                <p class="text-sm">Editor loaded revision: {{ $expectedRevision }}. Latest saved revision: {{ $page->draft_revision }}.</p>
                <p class="text-sm">Published: {{ $page->published_revision_id ? 'A published snapshot exists' : 'Not published' }}</p>
                <button type="button" wire:click="reloadDraft" wire:confirm="Discard unsaved changes and load the latest draft?"
                    class="rounded-lg border px-3 py-2 text-sm">Reload latest draft</button>
            </section>

            <section class="space-y-4" aria-label="Draft page sections">
                <h2 class="text-lg font-semibold">Page sections</h2>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="addSection('text')" class="rounded-lg border px-3 py-2">+ Text</button>
                    <button type="button" wire:click="addSection('hero')" class="rounded-lg border px-3 py-2">+ Hero</button>
                    <button type="button" wire:click="addSection('cta')" class="rounded-lg border px-3 py-2">+ Call to action</button>
                    <button type="button" wire:click="addColumns(1)" class="rounded-lg border px-3 py-2">+ 1 column</button>
                    <button type="button" wire:click="addColumns(2)" class="rounded-lg border px-3 py-2">+ 2 columns</button>
                    <button type="button" wire:click="addColumns(3)" class="rounded-lg border px-3 py-2">+ 3 columns</button>
                </div>
                @forelse ($sections as $index => $section)
                    <div wire:key="section-{{ $section['id'] ?? $index }}" class="space-y-3 rounded-xl border p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-semibold">{{ ucfirst($section['type'] ?? 'Section') }} #{{ $index + 1 }}</h3>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="moveSection({{ $index }}, 'up')" @disabled($index === 0)
                                    aria-label="Move section {{ $index + 1 }} up" class="rounded border px-2 py-1">↑</button>
                                <button type="button" wire:click="moveSection({{ $index }}, 'down')" @disabled($index === count($sections) - 1)
                                    aria-label="Move section {{ $index + 1 }} down" class="rounded border px-2 py-1">↓</button>
                                <button type="button" wire:click="removeSection({{ $index }})" wire:confirm="Remove this section from the next draft?"
                                    class="rounded border px-2 py-1">Remove</button>
                            </div>
                        </div>
                        @foreach (($section['data'] ?? []) as $field => $value)
                            @if (is_string($value) && ! in_array($field, ['imageId'], true))
                                <label class="block space-y-1">
                                    <span class="text-sm font-medium">{{ \Illuminate\Support\Str::headline($field) }}</span>
                                    <textarea wire:model="sections.{{ $index }}.data.{{ $field }}" rows="2"
                                        class="w-full rounded-lg border p-2 text-gray-900" maxlength="5000"></textarea>
                                </label>
                            @endif
                        @endforeach
                        @if (($section['type'] ?? '') === 'columns')
                            <div class="space-y-3">
                                <h4 class="text-sm font-semibold">Desktop proportions</h4>
                                @if (count($section['columns'] ?? []) === 2)
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" wire:click="setDesktopWidths({{ $index }}, 'equal')" class="rounded border px-2 py-1">Equal 6 / 6</button>
                                        <button type="button" wire:click="setDesktopWidths({{ $index }}, 'wideLeft')" class="rounded border px-2 py-1">Wide left 8 / 4</button>
                                        <button type="button" wire:click="setDesktopWidths({{ $index }}, 'wideRight')" class="rounded border px-2 py-1">Wide right 4 / 8</button>
                                    </div>
                                @elseif (count($section['columns'] ?? []) === 3)
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" wire:click="setDesktopWidths({{ $index }}, 'equal')" class="rounded border px-2 py-1">Equal 4 / 4 / 4</button>
                                        <button type="button" wire:click="setDesktopWidths({{ $index }}, 'wideCenter')" class="rounded border px-2 py-1">Wide center 3 / 6 / 3</button>
                                    </div>
                                @endif
                                <div class="grid gap-3 md:grid-cols-2">
                                    @foreach (($section['columns'] ?? []) as $columnIndex => $column)
                                        <div wire:key="col-{{ $column['id'] ?? $columnIndex }}" class="space-y-2 rounded-lg border p-3">
                                            <p class="text-sm font-semibold">
                                                Desktop column {{ $columnIndex + 1 }} ({{ $column['widthDesktop'] ?? '?' }}/12)
                                            </p>
                                            <p class="text-sm">Phone order: {{ $column['mobileOrder'] ?? '?' }}</p>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="moveMobileColumn({{ $index }}, {{ $columnIndex }}, 'up')"
                                                    @disabled(($column['mobileOrder'] ?? 1) <= 1)
                                                    class="rounded border px-2 py-1 text-sm">Phone earlier</button>
                                                <button type="button" wire:click="moveMobileColumn({{ $index }}, {{ $columnIndex }}, 'down')"
                                                    @disabled(($column['mobileOrder'] ?? count($section['columns'])) >= count($section['columns']))
                                                    class="rounded border px-2 py-1 text-sm">Phone later</button>
                                            </div>
                                            @foreach (($column['blocks'] ?? []) as $blockIndex => $block)
                                                @foreach (($block['data'] ?? []) as $field => $value)
                                                    @if (is_string($value) && ! in_array($field, ['imageId'], true))
                                                        <label class="block space-y-1">
                                                            <span class="text-sm">{{ \Illuminate\Support\Str::headline($field) }}</span>
                                                            <textarea wire:model="sections.{{ $index }}.columns.{{ $columnIndex }}.blocks.{{ $blockIndex }}.data.{{ $field }}"
                                                                rows="2" maxlength="5000" class="w-full rounded border p-2 text-gray-900"></textarea>
                                                        </label>
                                                    @endif
                                                @endforeach
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if (array_key_exists('imageId', $section['data'] ?? []) || array_key_exists('mediaIds', $section['data'] ?? []))
                            <p class="text-sm">Existing media references are preserved. Replacements require the approved media library.</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm">This page has no draft sections yet. Add a section to begin.</p>
                @endforelse
            </section>

            <section class="space-y-3 rounded-xl border p-4" aria-label="Responsive draft preview">
                <h2 class="text-lg font-semibold">Draft preview</h2>
                <p class="text-sm">Only safe text and layout placeholders appear here. No unapproved images or live booking data.</p>
                <div role="group" aria-label="Preview screen size" class="flex flex-wrap gap-2">
                    <button type="button" wire:click="setPreviewMode('desktop')" class="rounded border px-3 py-2" aria-pressed="{{ $previewMode === 'desktop' ? 'true' : 'false' }}">Desktop</button>
                    <button type="button" wire:click="setPreviewMode('tablet')" class="rounded border px-3 py-2" aria-pressed="{{ $previewMode === 'tablet' ? 'true' : 'false' }}">Tablet</button>
                    <button type="button" wire:click="setPreviewMode('phone')" class="rounded border px-3 py-2" aria-pressed="{{ $previewMode === 'phone' ? 'true' : 'false' }}">Phone</button>
                </div>
                @include('filament.pages.partials.draft-preview', ['sections' => $sections, 'previewMode' => $previewMode])
            </section>

            <section class="space-y-3 rounded-xl border p-4" aria-label="Save and publish">
                <label class="block space-y-2">
                    <span class="text-sm font-medium">Reason for this change (required)</span>
                    <textarea wire:model="reason" rows="2" maxlength="500" class="w-full rounded-lg border p-2 text-gray-900"
                        placeholder="What changed and why?"></textarea>
                </label>
                @error('reason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @error('sections') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="saveDraft" wire:loading.attr="disabled"
                        class="rounded-lg border px-4 py-2 font-semibold">Save draft</button>
                    @if ($this->canPublishCurrentPage())
                        <button type="button" wire:click="publishDraft" wire:confirm="Publish the saved revision to your venue website?"
                            wire:loading.attr="disabled" class="rounded-lg border px-4 py-2 font-semibold">Publish saved draft</button>
                    @endif
                </div>
                @if ($this->canPublishCurrentPage() && $page->published_revision_id)
                    <div class="space-y-2 border-t pt-4">
                        <h3 class="font-semibold">Previously published versions</h3>
                        <p class="text-sm">Restoring changes only the published pointer. Existing drafts and history are preserved. Enter a reason above.</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->publishedHistory() as $snapshot)
                                @if ($snapshot->id === $page->published_revision_id)
                                    <span class="rounded-lg border px-3 py-2 text-sm">Revision {{ $snapshot->revision_number }} is live</span>
                                @else
                                    <button type="button"
                                        wire:click="restorePublishedRevision('{{ $snapshot->id }}')"
                                        wire:confirm="Restore this previously published version? Current draft edits will be preserved."
                                        class="rounded-lg border px-3 py-2 text-sm">
                                        Restore revision {{ $snapshot->revision_number }}
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
                <p class="text-sm">Publishing changes only the approved snapshot. Responsive visual preview is an upcoming control.</p>
            </section>
        @else
            <p role="status">No accessible page is selected.</p>
        @endif
    </div>
</x-filament-panels::page>
