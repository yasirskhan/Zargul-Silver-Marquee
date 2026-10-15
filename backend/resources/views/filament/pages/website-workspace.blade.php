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

            <section class="space-y-4 rounded-xl border p-4" aria-label="Website media library">
                <h2 class="text-lg font-semibold">Media library</h2>
                <p class="text-sm">Stage genuine venue images privately, then request owner or publisher approval before any publication. Uploading never puts a photo on the public website.</p>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Venue photograph (JPEG, PNG or WebP; up to 4 MB)</span>
                        <input type="file" wire:model="pendingImage" accept="image/jpeg,image/png,image/webp"
                            class="block w-full rounded border p-2 text-sm">
                        @error('pendingImage') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Image description for accessibility</span>
                        <input type="text" wire:model="mediaAltText" maxlength="300"
                            placeholder="Describe the actual venue image" class="w-full rounded border p-2 text-gray-900">
                        @error('mediaAltText') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </label>
                </div>
                <label class="block space-y-1">
                    <span class="text-sm font-medium">Media upload or approval reason</span>
                    <textarea wire:model="mediaReason" rows="2" maxlength="500"
                        class="w-full rounded border p-2 text-gray-900" placeholder="Why is this approved venue image needed?"></textarea>
                    @error('mediaReason') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>
                <button type="button" wire:click="stageMedia" wire:loading.attr="disabled"
                    class="rounded-lg border px-3 py-2 font-semibold">Stage image privately</button>
                <div class="space-y-2">
                    <h3 class="font-semibold">Company image assets</h3>
                    @forelse ($this->mediaForCurrentCompany() as $asset)
                        <div wire:key="asset-{{ $asset->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
                            <div>
                                <p class="text-sm font-medium">{{ $asset->alt_text }}</p>
                                <p class="text-xs">{{ $asset->mime_type }} · {{ ucfirst($asset->status) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="addImageFromLibrary('{{ $asset->id }}')"
                                    class="rounded border px-2 py-1 text-sm">Add to draft</button>
                                @if ($this->canPublishCurrentPage() && $asset->status === 'pending')
                                    <button type="button" wire:click="approveMedia('{{ $asset->id }}')"
                                        wire:confirm="Approve this actual venue image for a published page?"
                                        class="rounded border px-2 py-1 text-sm">Approve image</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm">No staged venue images yet.</p>
                    @endforelse
                </div>
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
                    <div wire:key="section-{{ $section['id'] ?? $index }}"
                        x-on:dragover.prevent="if ($event.dataTransfer.types.includes('application/x-venuedesk-section')) $event.dataTransfer.dropEffect = 'move'"
                        x-on:drop.prevent.stop="if ($event.dataTransfer.types.includes('application/x-venuedesk-section')) $wire.moveSectionTo(Number($event.dataTransfer.getData('application/x-venuedesk-section')), {{ $index }})"
                        class="space-y-3 rounded-xl border p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span draggable="true" role="button" tabindex="0"
                                    title="Drag to reorder this section; use arrow buttons for keyboard or touch"
                                    aria-label="Drag section {{ $index + 1 }} to reorder"
                                    x-on:dragstart="$event.dataTransfer.setData('application/x-venuedesk-section', '{{ $index }}'); $event.dataTransfer.effectAllowed = 'move'"
                                    class="cursor-grab select-none rounded border px-2 py-1 text-sm" aria-hidden="true">⠿</span>
                                <h3 class="font-semibold">{{ ucfirst($section['type'] ?? 'Section') }} #{{ $index + 1 }}</h3>
                            </div>
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
                                        <div wire:key="col-{{ $column['id'] ?? $columnIndex }}"
                                            x-on:dragover.prevent.stop="if ($event.dataTransfer.types.includes('application/x-venuedesk-column')) $event.dataTransfer.dropEffect = 'move'"
                                            x-on:drop.prevent.stop="if ($event.dataTransfer.types.includes('application/x-venuedesk-column')) { let parts = $event.dataTransfer.getData('application/x-venuedesk-column').split(':').map(Number); if (parts[0] === {{ $index }}) $wire.moveDesktopColumn({{ $index }}, parts[1], {{ $columnIndex }}); }"
                                            class="space-y-2 rounded-lg border p-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span draggable="true" aria-hidden="true"
                                                    title="Drag to reorder desktop columns; keyboard and touch use buttons"
                                                    x-on:dragstart.stop="$event.dataTransfer.setData('application/x-venuedesk-column', '{{ $index }}:{{ $columnIndex }}'); $event.dataTransfer.effectAllowed = 'move'"
                                                    class="cursor-grab select-none rounded border px-2 py-1 text-sm">⠿</span>
                                                <p class="text-sm font-semibold">
                                                    Desktop column {{ $columnIndex + 1 }} ({{ $column['widthDesktop'] ?? '?' }}/12)
                                                </p>
                                            </div>
                                            <p class="text-sm">Phone order: {{ $column['mobileOrder'] ?? '?' }}</p>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="moveDesktopColumn({{ $index }}, {{ $columnIndex }}, {{ max(0, $columnIndex - 1) }})"
                                                    @disabled($columnIndex === 0) class="rounded border px-2 py-1 text-sm">Desktop left</button>
                                                <button type="button" wire:click="moveDesktopColumn({{ $index }}, {{ $columnIndex }}, {{ min(count($section['columns']) - 1, $columnIndex + 1) }})"
                                                    @disabled($columnIndex === count($section['columns']) - 1) class="rounded border px-2 py-1 text-sm">Desktop right</button>
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
