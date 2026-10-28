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
            <section class="space-y-3 rounded-xl border p-4" aria-label="Create an unpublished website page">
                <h2 class="text-lg font-semibold">Create a new website page</h2>
                <p class="text-sm">New pages stay private until an authorized publisher approves the saved draft. Only pre-approved public paths can be created.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-sm">Page path</span>
                        <select wire:model="newPageSlug" class="w-full rounded-lg border p-3 text-gray-900">
                            @foreach (\App\Services\SiteWorkspaceService::NEW_PAGE_PATHS as $path => $label)
                                <option value="{{ ltrim($path, '/') }}">{{ $label }} ({{ $path }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm">Page title</span>
                        <input type="text" maxlength="120" wire:model="newPageTitle"
                            class="w-full rounded-lg border p-3 text-gray-900">
                    </label>
                </div>
                <label class="block space-y-1">
                    <span class="text-sm">Creation reason (no contact information)</span>
                    <textarea wire:model="newPageReason" maxlength="500" rows="2"
                        class="w-full rounded-lg border p-3 text-gray-900"></textarea>
                </label>
                @error('newPageSlug') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @error('newPageTitle') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                @error('newPageReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button type="button" wire:click="createNewPage" wire:loading.attr="disabled"
                    class="rounded-lg border px-4 py-2 font-semibold">Create unpublished page</button>
            </section>
            <section class="space-y-2 rounded-xl border p-5" aria-label="Website page status">
                <h2 class="text-lg font-semibold">{{ $page->title }}</h2>
                <p class="text-sm">Path: {{ $page->slug }}</p>
                <p class="text-sm">Editor loaded revision: {{ $expectedRevision }}. Latest saved revision: {{ $page->draft_revision }}.</p>
                <p class="text-sm">Published: {{ $page->published_revision_id ? 'A published snapshot exists' : 'Not published' }}</p>
                <button type="button" wire:click="reloadDraft" wire:confirm="Discard unsaved changes and load the latest draft?"
                    class="rounded-lg border px-3 py-2 text-sm">Reload latest draft</button>
            </section>

            <section class="space-y-3 rounded-xl border p-4" aria-label="Draft page search settings">
                <h2 class="text-lg font-semibold">Page search title</h2>
                <label class="block space-y-1">
                    <span class="text-sm font-medium">Search title override (optional)</span>
                    <input type="text" wire:model.live.debounce.400ms="pageSeoTitle" maxlength="120"
                        class="w-full rounded border p-2 text-gray-900"
                        placeholder="Leave blank to use the approved page title and venue name">
                </label>
                <p class="text-xs">Only saved and separately published page revisions change public metadata. Search indexing requires independent operator approval.</p>
                @error('pageSeoTitle') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <label class="block space-y-1">
                    <span class="text-sm font-medium">Page search description override (optional)</span>
                    <textarea wire:model.live.debounce.400ms="pageSeoDescription" maxlength="160" rows="2"
                        class="w-full rounded border p-2 text-gray-900"
                        placeholder="Leave blank to use published venue-wide search description"></textarea>
                </label>
                @error('pageSeoDescription') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="space-y-2 rounded-lg border bg-white p-3 text-gray-900" aria-label="Private page search preview">
                    <p class="text-xs font-semibold">Private search preview (not published)</p>
                    <p class="font-semibold text-blue-700">{{ $pageSeoTitle !== '' ? $pageSeoTitle : $page->title.' | '.($branding['siteName'] ?? $page->title) }}</p>
                    <p class="text-sm text-gray-700">{{ $pageSeoDescription !== '' ? $pageSeoDescription : (($branding['seoDescription'] ?? '') !== '' ? $branding['seoDescription'] : (($branding['tagline'] ?? '') !== '' ? $branding['tagline'] : ($branding['siteName'] ?? $page->title))) }}</p>
                    <p class="text-xs">This draft preview is private. Save and separately publish to change website metadata; indexing still requires operator approval.</p>
                </div>
            </section>

            <section class="space-y-4 rounded-xl border p-4" aria-label="Website branding">
                <h2 class="text-lg font-semibold">Branding and theme</h2>
                <p class="text-sm">Choose approved theme presets and company-owned images. Save a draft first; only an authorized publisher can approve changes for a future public release.</p>
                <p class="text-xs">Branding draft revision: {{ $expectedBrandingRevision }}. {{ $loadedBrandingPublishedRevisionId ? 'An approved branding snapshot exists.' : 'No published branding yet.' }}</p>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Public venue name</span>
                        <input wire:model.live.debounce.400ms="branding.siteName" type="text" maxlength="120" class="w-full rounded border p-2 text-gray-900">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Short tagline</span>
                        <input wire:model.live.debounce.400ms="branding.tagline" type="text" maxlength="240" class="w-full rounded border p-2 text-gray-900">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Color theme preset</span>
                        <select wire:model.live="branding.themePreset" class="w-full rounded border p-2 text-gray-900">
                            <option value="silver">Silver</option>
                            <option value="ivory">Ivory</option>
                            <option value="midnight">Midnight</option>
                            <option value="sage">Sage</option>
                        </select>
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Typography preset</span>
                        <select wire:model.live="branding.fontPreset" class="w-full rounded border p-2 text-gray-900">
                            <option value="classic">Classic</option>
                            <option value="modern">Modern</option>
                        </select>
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Structural layout</span>
                        <select wire:model.live="branding.layoutPreset" class="w-full rounded border p-2 text-gray-900">
                            <option value="classic">Classic cards</option>
                            <option value="editorial">Editorial</option>
                            <option value="minimal">Minimal</option>
                        </select>
                        <span class="text-xs">Approved structural template; publish branding separately to apply it.</span>
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Website logo</span>
                        <select wire:model.live="branding.logoMediaId" class="w-full rounded border p-2 text-gray-900">
                            <option value="">No logo selected</option>
                            @foreach ($this->mediaForCurrentCompany() as $asset)
                                <option value="{{ $asset->id }}">{{ $asset->alt_text }} ({{ $asset->status }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium">Website favicon</span>
                        <select wire:model.live="branding.faviconMediaId" class="w-full rounded border p-2 text-gray-900">
                            <option value="">No favicon selected</option>
                            @foreach ($this->mediaForCurrentCompany() as $asset)
                                <option value="{{ $asset->id }}">{{ $asset->alt_text }} ({{ $asset->status }})</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <label class="block space-y-1">
                    <span class="text-sm font-medium">Public footer message (optional)</span>
                    <textarea wire:model="branding.footerText" rows="2" maxlength="240"
                        class="w-full rounded border p-2 text-gray-900"
                        placeholder="A short approved venue message, not private contact details"></textarea>
                </label>
                <section class="space-y-3 rounded-lg border p-3" aria-label="Draft website navigation">
                    <h3 class="font-semibold">Website menu</h3>
                    <p class="text-sm">Reorder or hide approved website paths. Unpublished pages stay hidden even when selected here. Save and publish branding separately to update the public menu.</p>
                    <div class="space-y-2">
                        @foreach (($branding['navigationOrder'] ?? []) as $menuIndex => $path)
                            <div wire:key="menu-{{ trim($path, '/') ?: 'home' }}" class="flex flex-wrap items-center justify-between gap-2 rounded border p-2">
                                <span class="text-sm font-medium">{{ $path === '/' ? 'Home' : ucfirst(ltrim($path, '/')) }} ({{ $path }})</span>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="moveNavigationPath('{{ $path }}', 'up')"
                                        @disabled($menuIndex === 0) class="rounded border px-2 py-1 text-sm"
                                        aria-label="Move {{ $path }} menu link earlier">Earlier</button>
                                    <button type="button" wire:click="moveNavigationPath('{{ $path }}', 'down')"
                                        @disabled($menuIndex === count($branding['navigationOrder']) - 1)
                                        class="rounded border px-2 py-1 text-sm"
                                        aria-label="Move {{ $path }} menu link later">Later</button>
                                    @if ($path !== '/')
                                        <button type="button" wire:click="toggleNavigationPath('{{ $path }}')"
                                            class="rounded border px-2 py-1 text-sm"
                                            aria-label="Hide {{ $path }} from menu">Hide</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Services\SiteBrandingValidator::NAVIGATION_PATHS as $path)
                            @if ($path !== '/' && ! in_array($path, $branding['navigationOrder'] ?? [], true))
                                <button type="button" wire:click="toggleNavigationPath('{{ $path }}')"
                                    class="rounded border px-2 py-1 text-sm">Show {{ $path }}</button>
                            @endif
                        @endforeach
                    </div>
                    @error('branding.navigationOrder') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                </section>
                <label class="block space-y-1">
                    <span class="text-sm font-medium">Search description (optional)</span>
                    <textarea wire:model="branding.seoDescription" rows="2" maxlength="160"
                        class="w-full rounded border p-2 text-gray-900"
                        placeholder="Describe your venue in up to 160 characters"></textarea>
                    <span class="text-xs">Appears in search metadata only after separate branding publication. Indexing still requires operator approval.</span>
                </label>
                @if ($this->canPublishCurrentPage())
                    <section class="space-y-3 rounded-lg border p-3" aria-label="Private domain ownership">
                        <h3 class="font-semibold">Domain ownership</h3>
                        <p class="text-sm">Verify that you control a public DNS domain. Verification does not turn on the public website, publish drafts, or permit search indexing. Public launch still needs separate operator approval.</p>
                        <label class="block space-y-1">
                            <span class="text-sm font-medium">Public domain name only (no https://)</span>
                            <input type="text" wire:model="domainCandidate" maxlength="253"
                                placeholder="events.yourdomain.com" class="w-full rounded border p-2 text-gray-900">
                        </label>
                        @error('domainCandidate') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <label class="block space-y-1">
                            <span class="text-sm font-medium">Reason for domain ownership request</span>
                            <textarea wire:model="domainReason" maxlength="500" rows="2"
                                class="w-full rounded border p-2 text-gray-900"></textarea>
                        </label>
                        @error('domainReason') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="requestDomainOwnership"
                                wire:loading.attr="disabled" class="rounded border px-3 py-2">Create DNS TXT proof</button>
                            <button type="button" wire:click="verifyDomainOwnership"
                                wire:loading.attr="disabled" class="rounded border px-3 py-2">Verify DNS TXT proof</button>
                        </div>
                        @if ($domainChallengeName && $domainChallengeValue)
                            <div class="space-y-1 rounded border p-3" aria-label="Private DNS verification instructions">
                                <p class="text-sm">Create a TXT record in your DNS provider:</p>
                                <p class="text-sm">Name: <code class="break-all">{{ $domainChallengeName }}</code></p>
                                <p class="text-sm">Value: <code class="break-all">{{ $domainChallengeValue }}</code></p>
                                <p class="text-xs">Challenge expires: {{ $domainChallengeExpiresAt }}. The value is displayed only while this private editing session is open. Requesting a new challenge invalidates the previous one.</p>
                            </div>
                        @endif
                        @if (count($this->verifiedDomainClaims()))
                            <p class="text-sm">To disconnect a verified domain, enter that exact domain and a reason above; confirm revocation. Public requests will be denied until a new DNS proof is completed.</p>
                            <button type="button" wire:click="revokeDomainOwnership"
                                wire:confirm="Revoke this verified domain? Public website requests on this hostname will stop until a new DNS proof is verified."
                                wire:loading.attr="disabled" class="rounded border px-3 py-2">Revoke verified DNS ownership</button>
                            <p class="text-sm">Verified DNS ownership only: {{ implode(', ', $this->verifiedDomainClaims()) }}. Public release is still disabled until separately approved.</p>
                        @endif
                    </section>
                @endif
                <div class="space-y-3" role="region" aria-label="Branding draft appearance">
                    <div role="group" aria-label="Branding preview screen size" class="flex flex-wrap gap-2">
                        <button type="button" wire:click="setPreviewMode('desktop')" class="rounded border px-3 py-2" aria-pressed="{{ $previewMode === 'desktop' ? 'true' : 'false' }}">Desktop branding</button>
                        <button type="button" wire:click="setPreviewMode('tablet')" class="rounded border px-3 py-2" aria-pressed="{{ $previewMode === 'tablet' ? 'true' : 'false' }}">Tablet branding</button>
                        <button type="button" wire:click="setPreviewMode('phone')" class="rounded border px-3 py-2" aria-pressed="{{ $previewMode === 'phone' ? 'true' : 'false' }}">Phone branding</button>
                    </div>
                    @include('filament.pages.partials.branding-preview', ['branding' => $branding, 'previewMode' => $previewMode])
                </div>
                @error('branding') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                @foreach (['siteName', 'tagline', 'footerText', 'seoDescription', 'themePreset', 'fontPreset', 'logoMediaId', 'faviconMediaId'] as $field)
                    @error('branding.'.$field) <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                @endforeach
                <label class="block space-y-1">
                    <span class="text-sm font-medium">Reason for branding change or approval</span>
                    <textarea wire:model="brandingReason" maxlength="500" rows="2"
                        class="w-full rounded border p-2 text-gray-900"></textarea>
                    @error('brandingReason') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                </label>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="saveBrandingDraft" wire:loading.attr="disabled"
                        class="rounded border px-3 py-2 font-semibold">Save branding draft</button>
                    <button type="button" wire:click="reloadBranding"
                        wire:confirm="Discard unsaved branding changes and load the latest draft?"
                        class="rounded border px-3 py-2">Reload branding</button>
                    @if ($this->canPublishCurrentPage())
                        <button type="button" wire:click="publishBranding"
                            wire:confirm="Approve saved branding for public release?"
                            class="rounded border px-3 py-2 font-semibold">Publish branding</button>
                    @endif
                </div>
                @if ($this->canPublishCurrentPage())
                    <div class="space-y-2" aria-label="Published branding versions">
                        <h3 class="font-semibold">Previously approved branding</h3>
                        <p class="text-xs">Restoring an older approved version changes only the published pointer. Your latest draft remains unchanged. Enter a reason above first.</p>
                        <div class="flex flex-wrap gap-2">
                            @forelse ($this->brandingPublishedHistory() as $approved)
                                <button type="button" wire:click="restoreBranding('{{ $approved->id }}')"
                                    wire:confirm="Restore this previously approved branding snapshot? Current draft edits will be preserved."
                                    class="rounded border px-2 py-1 text-sm">Restore branding revision {{ $approved->revision_number }}</button>
                            @empty
                                <p class="text-sm">No approved branding history yet.</p>
                            @endforelse
                        </div>
                    </div>
                @endif
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
                        @php($assetUrl = $this->mediaPreviewUrl($asset->id))
                        <div wire:key="asset-{{ $asset->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($assetUrl)
                                    <img src="{{ $assetUrl }}" alt="{{ $asset->alt_text }}"
                                        width="96" height="64" loading="lazy"
                                        class="h-16 w-24 rounded border object-cover">
                                @else
                                    <span class="grid h-16 w-24 place-items-center rounded border text-xs">No preview</span>
                                @endif
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">{{ $asset->alt_text }}</p>
                                    <p class="text-xs">{{ $asset->mime_type }} · {{ ucfirst($asset->status) }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="addImageFromLibrary('{{ $asset->id }}')"
                                    class="rounded border px-2 py-1 text-sm">Add to draft</button>
                                <button type="button" wire:click="selectReplacement('{{ $asset->id }}')"
                                    class="rounded border px-2 py-1 text-sm">Replace</button>
                                <button type="button" wire:click="retireMedia('{{ $asset->id }}')"
                                    wire:confirm="Retire this image? It will be refused if a published page still uses it."
                                    class="rounded border px-2 py-1 text-sm">Retire</button>
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
                @if ($replacementMediaId !== '')
                    <section class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3" aria-label="Stage media replacement">
                        <h3 class="font-semibold">Stage a replacement image</h3>
                        <p class="text-sm">A replacement is a new private asset. Published revisions keep their existing image; insert the new asset deliberately.</p>
                        <input type="file" wire:model="replacementImage" accept="image/jpeg,image/png,image/webp"
                            class="block w-full rounded border p-2 text-sm">
                        @error('replacementImage') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                        <input type="text" wire:model="replacementAltText" maxlength="300"
                            aria-label="Replacement image description"
                            class="w-full rounded border p-2 text-gray-900">
                        @error('replacementAltText') <span role="alert" class="text-sm text-red-600">{{ $message }}</span> @enderror
                        <button type="button" wire:click="stageReplacementMedia"
                            class="rounded-lg border px-3 py-2 font-semibold">Stage replacement</button>
                        <button type="button" wire:click="$set('replacementMediaId', '')"
                            class="rounded border px-3 py-2">Cancel</button>
                    </section>
                @endif
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
                @php($columnImageAssets = $this->mediaForCurrentCompany())
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
                            @if (is_string($value) && ! in_array($field, ['imageId', 'focalX', 'focalY', 'cropAspect'], true))
                                <label class="block space-y-1">
                                    <span class="text-sm font-medium">{{ \Illuminate\Support\Str::headline($field) }}</span>
                                    <textarea wire:model="sections.{{ $index }}.data.{{ $field }}" rows="2"
                                        class="w-full rounded-lg border p-2 text-gray-900" maxlength="5000"></textarea>
                                </label>
                            @endif
                        @endforeach
                        @if (! empty($section['data']['imageId']))
                            <div class="grid gap-2 rounded-lg border p-3 md:grid-cols-3" aria-label="Image crop and focal point">
                                <label class="block space-y-1">
                                    <span class="text-sm">Focal X (%)</span>
                                    <input type="number" min="0" max="100"
                                        wire:model="sections.{{ $index }}.data.focalX"
                                        value="{{ $section['data']['focalX'] ?? '50' }}"
                                        class="w-full rounded border p-2 text-gray-900">
                                </label>
                                <label class="block space-y-1">
                                    <span class="text-sm">Focal Y (%)</span>
                                    <input type="number" min="0" max="100"
                                        wire:model="sections.{{ $index }}.data.focalY"
                                        value="{{ $section['data']['focalY'] ?? '50' }}"
                                        class="w-full rounded border p-2 text-gray-900">
                                </label>
                                <label class="block space-y-1">
                                    <span class="text-sm">Crop</span>
                                    <select wire:model="sections.{{ $index }}.data.cropAspect"
                                        class="w-full rounded border p-2 text-gray-900">
                                        @foreach (['original' => 'Original', '16:9' => '16:9', '4:3' => '4:3', '1:1' => 'Square'] as $cropValue => $cropLabel)
                                            <option value="{{ $cropValue }}" @selected(($section['data']['cropAspect'] ?? '16:9') === $cropValue)>{{ $cropLabel }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <p class="text-xs md:col-span-3">The crop and focal point are saved with the draft revision and only reach the public page after publish.</p>
                            </div>
                        @endif
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
                                            <div class="flex flex-wrap gap-2" aria-label="Add content to desktop column {{ $columnIndex + 1 }}">
                                                <button type="button" wire:click="addColumnBlock({{ $index }}, {{ $columnIndex }}, 'text')"
                                                    @disabled(count($column['blocks'] ?? []) >= 20) class="rounded border px-2 py-1 text-sm">+ Text block</button>
                                                <button type="button" wire:click="addColumnBlock({{ $index }}, {{ $columnIndex }}, 'cta')"
                                                    @disabled(count($column['blocks'] ?? []) >= 20) class="rounded border px-2 py-1 text-sm">+ Call-to-action block</button>
                                            </div>
                                            <div class="flex flex-wrap items-end gap-2">
                                                <label class="block min-w-[160px] flex-1 space-y-1">
                                                    <span class="text-sm">Image from venue library</span>
                                                    <select wire:model="columnMediaSelections.{{ $index }}.{{ $columnIndex }}"
                                                        aria-label="Image for column {{ $columnIndex + 1 }}"
                                                        class="w-full rounded border p-2 text-gray-900">
                                                        <option value="">Select a staged or approved image</option>
                                                        @foreach($columnImageAssets as $imageAsset)
                                                            <option value="{{ $imageAsset->id }}">{{ $imageAsset->alt_text }} ({{ $imageAsset->status }})</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <button type="button" wire:click="addImageToColumnFromLibrary({{ $index }}, {{ $columnIndex }})"
                                                    @disabled(count($column['blocks'] ?? []) >= 20 || count($columnImageAssets) === 0)
                                                    class="rounded border px-2 py-1 text-sm">+ Library image block</button>
                                            </div>
                                            @foreach (($column['blocks'] ?? []) as $blockIndex => $block)
                                                <div wire:key="nested-block-{{ $block['id'] ?? $blockIndex }}"
                                                    class="space-y-2 rounded-lg border p-3" aria-label="Content block {{ $blockIndex + 1 }} in column {{ $columnIndex + 1 }}">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <p class="font-medium text-sm">{{ \Illuminate\Support\Str::headline($block['type'] ?? 'text') }} block {{ $blockIndex + 1 }}</p>
                                                        <button type="button" wire:click="moveColumnBlock({{ $index }}, {{ $columnIndex }}, {{ $blockIndex }}, {{ $columnIndex }}, {{ $blockIndex - 1 }})"
                                                            @disabled($blockIndex === 0) class="rounded border px-2 py-1 text-sm">Move block up</button>
                                                        <button type="button" wire:click="moveColumnBlock({{ $index }}, {{ $columnIndex }}, {{ $blockIndex }}, {{ $columnIndex }}, {{ $blockIndex + 2 }})"
                                                            @disabled($blockIndex === count($column['blocks']) - 1) class="rounded border px-2 py-1 text-sm">Move block down</button>
                                                        @if($columnIndex > 0)
                                                            <button type="button" wire:click="moveColumnBlock({{ $index }}, {{ $columnIndex }}, {{ $blockIndex }}, {{ $columnIndex - 1 }}, {{ count($section['columns'][$columnIndex - 1]['blocks'] ?? []) }})"
                                                                @disabled(count($section['columns'][$columnIndex - 1]['blocks'] ?? []) >= 20) class="rounded border px-2 py-1 text-sm">Move block left</button>
                                                        @endif
                                                        @if($columnIndex < count($section['columns']) - 1)
                                                            <button type="button" wire:click="moveColumnBlock({{ $index }}, {{ $columnIndex }}, {{ $blockIndex }}, {{ $columnIndex + 1 }}, {{ count($section['columns'][$columnIndex + 1]['blocks'] ?? []) }})"
                                                                @disabled(count($section['columns'][$columnIndex + 1]['blocks'] ?? []) >= 20) class="rounded border px-2 py-1 text-sm">Move block right</button>
                                                        @endif
                                                        <button type="button" wire:click="removeColumnBlock({{ $index }}, {{ $columnIndex }}, {{ $blockIndex }})"
                                                            wire:confirm="Remove this block from the draft?" class="rounded border px-2 py-1 text-sm">Remove block</button>
                                                    </div>
                                                    @foreach (($block['data'] ?? []) as $field => $value)
                                                        @if (is_string($value) && ! in_array($field, ['imageId', 'focalX', 'focalY', 'cropAspect'], true))
                                                            <label class="block space-y-1">
                                                                <span class="text-sm">{{ \Illuminate\Support\Str::headline($field) }}</span>
                                                                <textarea wire:model="sections.{{ $index }}.columns.{{ $columnIndex }}.blocks.{{ $blockIndex }}.data.{{ $field }}"
                                                                    rows="2" maxlength="5000" class="w-full rounded border p-2 text-gray-900"></textarea>
                                                            </label>
                                                        @endif
                                                    @endforeach
                                                    @if (! empty($block['data']['imageId']))
                                                        <div class="grid gap-2 rounded-lg border p-3 md:grid-cols-3" aria-label="Image crop and focal point">
                                                            <label class="block space-y-1">
                                                                <span class="text-sm">Focal X (%)</span>
                                                                <input type="number" min="0" max="100"
                                                                    wire:model="sections.{{ $index }}.columns.{{ $columnIndex }}.blocks.{{ $blockIndex }}.data.focalX"
                                                                    value="{{ $block['data']['focalX'] ?? '50' }}"
                                                                    class="w-full rounded border p-2 text-gray-900">
                                                            </label>
                                                            <label class="block space-y-1">
                                                                <span class="text-sm">Focal Y (%)</span>
                                                                <input type="number" min="0" max="100"
                                                                    wire:model="sections.{{ $index }}.columns.{{ $columnIndex }}.blocks.{{ $blockIndex }}.data.focalY"
                                                                    value="{{ $block['data']['focalY'] ?? '50' }}"
                                                                    class="w-full rounded border p-2 text-gray-900">
                                                            </label>
                                                            <label class="block space-y-1">
                                                                <span class="text-sm">Crop</span>
                                                                <select wire:model="sections.{{ $index }}.columns.{{ $columnIndex }}.blocks.{{ $blockIndex }}.data.cropAspect"
                                                                    class="w-full rounded border p-2 text-gray-900">
                                                                    @foreach (['original' => 'Original', '16:9' => '16:9', '4:3' => '4:3', '1:1' => 'Square'] as $cropValue => $cropLabel)
                                                                        <option value="{{ $cropValue }}" @selected(($block['data']['cropAspect'] ?? '16:9') === $cropValue)>{{ $cropLabel }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </label>
                                                        </div>
                                                    @endif
                                                </div>
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
                <p class="text-sm">Authorized company images and safe typed text appear here. This draft is private and never public until an authorized publish; live booking data is not loaded.</p>
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
                        @if ($page->published_revision_id)
                            <button type="button" wire:click="unpublishCurrentPage"
                                wire:confirm="Take this published page offline? Its saved drafts, approved versions and audit history will be preserved."
                                wire:loading.attr="disabled"
                                class="rounded-lg border px-4 py-2 font-semibold">Take published page offline</button>
                        @endif
                    @endif
                </div>
                @if ($this->canPublishCurrentPage() && count($this->publishedHistory()) > 0)
                    <div class="space-y-2 border-t pt-4">
                        <h3 class="font-semibold">Previously published versions</h3>
                        <p class="text-sm">Restoring a previously published version can bring an offline page back. Existing drafts and history are preserved. Enter a reason above.</p>
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
