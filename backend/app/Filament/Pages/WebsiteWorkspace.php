<?php

namespace App\Filament\Pages;

use App\Models\SitePage;
use App\Models\User;
use App\Services\SiteBrandingService;
use App\Services\SiteMediaService;
use App\Services\SitePagePublishingService;
use App\Services\SiteWorkspaceService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WebsiteWorkspace extends Page
{
    use WithFileUploads;
    protected string $view = 'filament.pages.website-workspace';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Website';

    protected static ?string $slug = 'website';

    public ?string $companyId = null;

    public ?string $pageId = null;

    /** Typed sections are an editable draft working copy, never live published content. */
    public array $sections = [];

    public string $previewMode = 'desktop';

    public string $reason = '';

    /** Livewire temporary uploads are not publicly attached until privately staged. */
    public $pendingImage = null;

    public string $mediaAltText = '';

    public string $mediaReason = '';

    /** Owner-editable branding form, separate from approved public settings. */
    public array $branding = [];

    public string $brandingReason = '';

    #[Locked]
    public ?string $loadedPageId = null;

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public int $expectedRevision = 0;

    #[Locked]
    public ?string $loadedPublishedRevisionId = null;

    #[Locked]
    public ?string $loadedBrandingWebsiteId = null;

    #[Locked]
    public ?string $loadedBrandingCompanyId = null;

    #[Locked]
    public int $expectedBrandingRevision = 0;

    #[Locked]
    public ?string $loadedBrandingPublishedRevisionId = null;

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User
            && $actor->hasVerifiedEmail()
            && app(SiteWorkspaceService::class)->companyOptions($actor) !== [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->companyId = array_key_first($this->companyOptions());
        $this->pageId = array_key_first($this->pageOptions());
        $this->reloadDraft();
    }

    public function hydrate(): void
    {
        // Refresh membership authorization on every Filament/Livewire request.
        abort_unless(static::canAccess(), 403);
    }

    public function updatedCompanyId(): void
    {
        $this->pageId = array_key_first($this->pageOptions());
        $this->reloadDraft();
    }

    public function updatedPageId(): void
    {
        $this->reloadDraft();
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User
            ? app(SiteWorkspaceService::class)->companyOptions($actor)
            : [];
    }

    public function pageOptions(): array
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && $this->companyId
            ? app(SiteWorkspaceService::class)->pageOptions($actor, $this->companyId)
            : [];
    }

    public function currentPage(): ?SitePage
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && $this->companyId && $this->pageId
            ? app(SiteWorkspaceService::class)->page($actor, $this->companyId, $this->pageId)
            : null;
    }

    public function canPublishCurrentPage(): bool
    {
        $actor = Filament::auth()->user();
        $page = $this->currentPage();

        return $actor instanceof User && $page && Gate::forUser($actor)->allows('publish', $page);
    }

    /** Reload only an authorized page's latest draft. This also abandons unsaved changes. */
    public function reloadDraft(): void
    {
        $this->sections = [];
        $this->expectedRevision = 0;
        $this->loadedPublishedRevisionId = null;
        $this->loadedPageId = null;
        $this->loadedCompanyId = null;
        // Switching venue/page must never leave a different company's branding in Livewire state.
        $this->branding = [];
        $this->loadedBrandingWebsiteId = null;
        $this->loadedBrandingCompanyId = null;
        $this->expectedBrandingRevision = 0;
        $this->loadedBrandingPublishedRevisionId = null;

        $page = $this->currentPage();
        if (! $page) {
            return;
        }

        $this->authorizeSelectedPage('update');

        if ((int) $page->draft_revision > 0) {
            $revision = DB::table('site_page_revisions')
                ->where('company_id', $page->company_id)
                ->where('page_id', $page->getKey())
                ->where('revision_number', $page->draft_revision)
                ->first(['content']);
            if (! $revision) {
                throw new ConflictHttpException('The website draft cannot be loaded.');
            }
            $content = json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR);
            $this->sections = $content['sections'];
        }

        $this->loadedPageId = (string) $page->getKey();
        $this->loadedCompanyId = $page->company_id;
        $this->expectedRevision = (int) $page->draft_revision;
        $this->loadedPublishedRevisionId = $page->published_revision_id;
        $this->reloadBranding();
    }

    /** Load the current saved branding DRAFT, never the published snapshot as a fallback. */
    public function reloadBranding(): void
    {
        [, $page] = $this->authorizeSelectedPage('update');
        $this->checkEditorSnapshot($page);
        $site = DB::table('websites')
            ->where('company_id', $page->company_id)->where('id', $page->website_id)
            ->where('is_active', true)->first();
        abort_unless($site, 404);

        $this->loadedBrandingWebsiteId = $site->id;
        $this->loadedBrandingCompanyId = $site->company_id;
        $this->expectedBrandingRevision = (int) $site->branding_draft_revision;
        $this->loadedBrandingPublishedRevisionId = $site->branding_published_revision_id;
        $this->branding = [
            'siteName' => $site->name,
            'tagline' => '',
            'themePreset' => 'silver',
            'fontPreset' => 'classic',
            'logoMediaId' => null,
            'faviconMediaId' => null,
        ];
        if ($this->expectedBrandingRevision > 0) {
            $saved = DB::table('site_branding_revisions')
                ->where('company_id', $page->company_id)->where('website_id', $site->id)
                ->where('revision_number', $this->expectedBrandingRevision)->value('content');
            if (! is_string($saved)) {
                throw new ConflictHttpException('Branding draft is missing; reload your venue.');
            }
            $this->branding = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    private function authorizeBranding(string $ability): array
    {
        [$actor, $page] = $this->authorizeSelectedPage($ability);
        $this->checkEditorSnapshot($page);
        abort_unless($this->loadedBrandingCompanyId === $page->company_id
            && $this->loadedBrandingWebsiteId === $page->website_id, 409);

        return [$actor, $page];
    }

    private function normalizeBrandingForm(): void
    {
        foreach (['logoMediaId', 'faviconMediaId'] as $field) {
            if (($this->branding[$field] ?? null) === '') {
                $this->branding[$field] = null;
            }
        }
    }

    public function saveBrandingDraft(): void
    {
        [$actor, $page] = $this->authorizeBranding('update');
        $this->normalizeBrandingForm();
        $this->validate([
            'brandingReason' => ['required', 'string', 'min:3', 'max:500'],
            'branding.siteName' => ['required', 'string', 'max:120'],
            'branding.tagline' => ['present', 'string', 'max:240'],
            'branding.themePreset' => ['required', 'string'],
            'branding.fontPreset' => ['required', 'string'],
            'branding.logoMediaId' => ['nullable', 'uuid'],
            'branding.faviconMediaId' => ['nullable', 'uuid'],
        ]);
        $this->expectedBrandingRevision = app(SiteBrandingService::class)->saveDraft(
            $actor, $page->company_id, $page->website_id,
            $this->branding, $this->expectedBrandingRevision, $this->brandingReason
        );
        $this->brandingReason = '';
        Notification::make()->title('Branding draft saved for review')->success()->send();
    }

    public function publishBranding(): void
    {
        [$actor, $page] = $this->authorizeBranding('publish');
        $this->normalizeBrandingForm();
        $this->validate(['brandingReason' => ['required', 'string', 'min:3', 'max:500']]);
        if ($this->expectedBrandingRevision < 1) {
            throw ValidationException::withMessages(['branding' => 'Save a branding draft before publishing.']);
        }

        $saved = DB::table('site_branding_revisions')
            ->where('company_id', $page->company_id)->where('website_id', $page->website_id)
            ->where('revision_number', $this->expectedBrandingRevision)->value('content');
        if (! is_string($saved)) {
            throw new ConflictHttpException('Branding draft changed; reload before publishing.');
        }
        if (json_decode($saved, true, 512, JSON_THROW_ON_ERROR) != $this->branding) {
            throw ValidationException::withMessages([
                'branding' => 'Save the changes on this screen before publishing branding.',
            ]);
        }

        $this->loadedBrandingPublishedRevisionId = app(SiteBrandingService::class)->publish(
            $actor, $page->company_id, $page->website_id,
            $this->expectedBrandingRevision, $this->brandingReason
        );
        $this->brandingReason = '';
        Notification::make()->title('Approved branding snapshot updated')->success()->send();
    }

    /** Only published versions from this exact website are eligible for owner rollback. */
    public function brandingPublishedHistory(): array
    {
        $actor = Filament::auth()->user();
        $page = $this->currentPage();
        if (! $actor instanceof User || ! $page
            || $this->loadedBrandingCompanyId !== $page->company_id
            || $this->loadedBrandingWebsiteId !== $page->website_id
            || ! Gate::forUser($actor)->allows('publish', $page)) {
            return [];
        }

        return DB::table('site_branding_publishing_events')
            ->join('site_branding_revisions', function ($join): void {
                $join->on('site_branding_publishing_events.website_id', '=', 'site_branding_revisions.website_id')
                    ->on('site_branding_publishing_events.revision_id', '=', 'site_branding_revisions.id');
            })
            ->where('site_branding_publishing_events.company_id', $page->company_id)
            ->where('site_branding_publishing_events.website_id', $page->website_id)
            ->select('site_branding_revisions.id', 'site_branding_revisions.revision_number')
            ->distinct()
            ->orderByDesc('site_branding_revisions.revision_number')
            ->limit(50)
            ->get()
            ->all();
    }

    public function restoreBranding(string $targetRevisionId): void
    {
        [$actor, $page] = $this->authorizeBranding('publish');
        $this->validate(['brandingReason' => ['required', 'string', 'min:3', 'max:500']]);
        if (! $this->loadedBrandingPublishedRevisionId) {
            throw new ConflictHttpException('No approved branding snapshot exists to restore.');
        }

        $this->loadedBrandingPublishedRevisionId = app(SiteBrandingService::class)
            ->restorePublishedBranding(
                $actor, $page->company_id, $page->website_id,
                $targetRevisionId, $this->loadedBrandingPublishedRevisionId,
                $this->brandingReason
            );
        $this->brandingReason = '';
        Notification::make()->title('Previously approved branding restored; latest draft preserved')->success()->send();
    }

    /** Tenant-scoped media list with no object keys or raw file endpoints. */
    public function mediaForCurrentCompany(): array
    {
        $actor = Filament::auth()->user();
        $page = $this->currentPage();
        if (! $actor instanceof User || ! $page
            || ! Gate::forUser($actor)->allows('update', $page)) {
            return [];
        }

        return DB::table('site_media')
            ->where('company_id', $page->company_id)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'status', 'alt_text', 'mime_type'])
            ->all();
    }

    public function stageMedia(): void
    {
        [$actor, $page] = $this->authorizeSelectedPage('update');
        $this->checkEditorSnapshot($page);
        $this->validate([
            'pendingImage' => ['required', 'file', 'max:4096'],
            'mediaAltText' => ['required', 'string', 'max:300'],
            'mediaReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        app(SiteMediaService::class)->stageImage(
            $actor, $page->company_id, $this->pendingImage,
            $this->mediaAltText, $this->mediaReason
        );
        $this->reset('pendingImage', 'mediaAltText', 'mediaReason');
        Notification::make()->title('Image staged privately for review')->success()->send();
    }

    public function approveMedia(string $mediaId): void
    {
        [$actor, $page] = $this->authorizeSelectedPage('publish');
        $this->checkEditorSnapshot($page);
        $this->validate(['mediaReason' => ['required', 'string', 'min:3', 'max:500']]);

        app(SiteMediaService::class)->approveImage(
            $actor, $page->company_id, $mediaId, $this->mediaReason
        );
        $this->mediaReason = '';
        Notification::make()->title('Image approved for publishing')->success()->send();
    }

    public function addImageFromLibrary(string $mediaId): void
    {
        [, $page] = $this->authorizeSelectedPage('update');
        $this->checkEditorSnapshot($page);
        abort_unless(count($this->sections) < 60, 422);
        $asset = DB::table('site_media')
            ->where('company_id', $page->company_id)
            ->where('id', $mediaId)
            ->whereIn('status', ['pending', 'approved'])
            ->first(['id', 'alt_text']);
        abort_unless($asset, 404);

        $this->sections[] = [
            'id' => 'section_'.Str::random(16),
            'type' => 'image',
            'data' => [
                'imageId' => $asset->id,
                'alt' => $asset->alt_text ?: 'Venue photograph',
                'caption' => '',
            ],
        ];
    }

    public function addSection(string $type): void
    {
        $this->authorizeSelectedPage('update');

        $defaults = [
            'text' => ['title' => 'New section', 'body' => ''],
            'hero' => ['title' => 'Welcome', 'subtitle' => ''],
            'cta' => ['title' => 'Plan your event', 'body' => ''],
        ];
        abort_unless(isset($defaults[$type]) && count($this->sections) < 60, 422);

        $this->sections[] = [
            'id' => 'section_'.Str::random(16),
            'type' => $type,
            'data' => $defaults[$type],
        ];
    }

    /** Safe 1/2/3-column templates; no arbitrary HTML, CSS, or script fields. */
    public function addColumns(int $count): void
    {
        $this->authorizeSelectedPage('update');
        $widths = [
            1 => [12],
            2 => [6, 6],
            3 => [4, 4, 4],
        ];
        abort_unless(isset($widths[$count]) && count($this->sections) < 60, 422);

        $columns = [];
        foreach ($widths[$count] as $index => $width) {
            $columns[] = [
                'id' => 'column_'.Str::random(16),
                'widthDesktop' => $width,
                'mobileOrder' => $index + 1,
                'blocks' => [[
                    'id' => 'block_'.Str::random(16),
                    'type' => 'text',
                    'data' => ['title' => 'Column '.($index + 1), 'body' => ''],
                ]],
            ];
        }

        $this->sections[] = [
            'id' => 'section_'.Str::random(16),
            'type' => 'columns',
            'columns' => $columns,
        ];
    }

    /** Presets maintain the typed validator's total 12-unit desktop grid. */
    public function setDesktopWidths(int $sectionIndex, string $preset): void
    {
        $this->authorizeSelectedPage('update');
        $section = $this->sections[$sectionIndex] ?? null;
        abort_unless(is_array($section) && ($section['type'] ?? null) === 'columns'
            && is_array($section['columns'] ?? null), 422);

        $presets = [
            2 => ['equal' => [6, 6], 'wideLeft' => [8, 4], 'wideRight' => [4, 8]],
            3 => ['equal' => [4, 4, 4], 'wideCenter' => [3, 6, 3]],
        ];
        $widths = $presets[count($section['columns'])][$preset] ?? null;
        abort_unless($widths !== null, 422);
        foreach ($widths as $index => $width) {
            $this->sections[$sectionIndex]['columns'][$index]['widthDesktop'] = $width;
        }
    }

    /** Change phone stacking without altering desktop column order or widths. */
    public function moveMobileColumn(int $sectionIndex, int $columnIndex, string $direction): void
    {
        $this->authorizeSelectedPage('update');
        abort_unless(in_array($direction, ['up', 'down'], true), 422);
        $columns = $this->sections[$sectionIndex]['columns'] ?? null;
        abort_unless(($this->sections[$sectionIndex]['type'] ?? null) === 'columns'
            && is_array($columns) && isset($columns[$columnIndex]), 422);
        $oldOrder = (int) ($columns[$columnIndex]['mobileOrder'] ?? 0);
        $nextOrder = $oldOrder + ($direction === 'up' ? -1 : 1);
        foreach ($columns as $otherIndex => $column) {
            if ((int) ($column['mobileOrder'] ?? 0) === $nextOrder) {
                $this->sections[$sectionIndex]['columns'][$columnIndex]['mobileOrder'] = $nextOrder;
                $this->sections[$sectionIndex]['columns'][$otherIndex]['mobileOrder'] = $oldOrder;

                return;
            }
        }
        abort(422);
    }

    public function setPreviewMode(string $mode): void
    {
        $this->authorizeSelectedPage('view');
        abort_unless(in_array($mode, ['desktop', 'tablet', 'phone'], true), 422);
        $this->previewMode = $mode;
    }

    public function moveSection(int $index, string $direction): void
    {
        $this->authorizeSelectedPage('update');
        abort_unless(in_array($direction, ['up', 'down'], true), 422);
        $target = $index + ($direction === 'up' ? -1 : 1);
        abort_unless(isset($this->sections[$index], $this->sections[$target]), 422);
        [$this->sections[$index], $this->sections[$target]] =
            [$this->sections[$target], $this->sections[$index]];
    }

    /** Reorder by source and target indices; no arbitrary content enters from drop data. */
    public function moveSectionTo(int $from, int $to): void
    {
        $this->authorizeSelectedPage('update');
        abort_unless($from >= 0 && $to >= 0
            && $from < count($this->sections) && $to < count($this->sections), 422);

        if ($from === $to) {
            return;
        }

        $items = array_splice($this->sections, $from, 1);
        array_splice($this->sections, $to, 0, $items);
    }

    /**
     * Change desktop column positions while preserving every column's width,
     * original typed blocks and independently configured mobile order.
     */
    public function moveDesktopColumn(int $sectionIndex, int $from, int $to): void
    {
        $this->authorizeSelectedPage('update');
        abort_unless($sectionIndex >= 0 && isset($this->sections[$sectionIndex])
            && ($this->sections[$sectionIndex]['type'] ?? null) === 'columns'
            && is_array($this->sections[$sectionIndex]['columns'] ?? null), 422);

        $columns = &$this->sections[$sectionIndex]['columns'];
        abort_unless($from >= 0 && $to >= 0
            && $from < count($columns) && $to < count($columns), 422);
        if ($from === $to) {
            return;
        }

        $moved = array_splice($columns, $from, 1);
        array_splice($columns, $to, 0, $moved);
    }

    public function removeSection(int $index): void
    {
        $this->authorizeSelectedPage('update');
        abort_unless(isset($this->sections[$index]), 422);
        array_splice($this->sections, $index, 1);
    }

    public function saveDraft(): void
    {
        [$actor, $page] = $this->authorizeSelectedPage('update');
        $this->checkEditorSnapshot($page);
        $this->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'sections' => ['array', 'max:60'],
        ]);

        $this->expectedRevision = app(SitePagePublishingService::class)->saveDraft(
            $actor, $page->company_id, $page->getKey(),
            ['sections' => $this->sections], $this->expectedRevision, $this->reason
        );
        $this->reason = '';
        Notification::make()->title('Draft saved safely')->success()->send();
    }

    public function publishDraft(): void
    {
        [$actor, $page] = $this->authorizeSelectedPage('publish');
        $this->checkEditorSnapshot($page);
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        if ($this->expectedRevision < 1) {
            throw ValidationException::withMessages(['sections' => 'Save a draft before publishing.']);
        }

        $revision = DB::table('site_page_revisions')
            ->where('company_id', $page->company_id)
            ->where('page_id', $page->getKey())
            ->where('revision_number', $this->expectedRevision)
            ->first(['content']);
        if (! $revision) {
            throw new ConflictHttpException('Reload the draft before publishing.');
        }

        // Never silently publish an older snapshot while local unsaved edits are visible.
        $saved = json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR);
        if ($saved['sections'] != $this->sections) {
            throw ValidationException::withMessages([
                'sections' => 'Save your current changes before publishing.',
            ]);
        }

        $this->loadedPublishedRevisionId = app(SitePagePublishingService::class)->publish(
            $actor, $page->company_id, $page->getKey(), $this->expectedRevision, $this->reason
        );
        $this->reason = '';
        Notification::make()->title('Published snapshot updated')->success()->send();
    }

    /** Only an authorized publisher may inspect prior, genuinely published snapshots. */
    public function publishedHistory(): array
    {
        $actor = Filament::auth()->user();
        $page = $this->currentPage();
        if (! $actor instanceof User || ! $page
            || ! Gate::forUser($actor)->allows('publish', $page)) {
            return [];
        }

        return DB::table('site_publishing_events')
            ->join('site_page_revisions', function ($join): void {
                $join->on('site_publishing_events.page_id', '=', 'site_page_revisions.page_id')
                    ->on('site_publishing_events.revision_id', '=', 'site_page_revisions.id');
            })
            ->where('site_publishing_events.company_id', $page->company_id)
            ->where('site_publishing_events.page_id', $page->getKey())
            ->select('site_page_revisions.id', 'site_page_revisions.revision_number')
            ->distinct()
            ->orderByDesc('site_page_revisions.revision_number')
            ->limit(100)
            ->get()
            ->all();
    }

    public function restorePublishedRevision(string $targetRevisionId): void
    {
        [$actor, $page] = $this->authorizeSelectedPage('publish');
        $this->checkEditorSnapshot($page);
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        if (! $this->loadedPublishedRevisionId) {
            throw new ConflictHttpException('No published snapshot was loaded; reload this page.');
        }

        // The service checks prior-publication proof and compares the locked
        // expected live pointer in the same transaction as its immutable audit.
        $this->loadedPublishedRevisionId = app(SitePagePublishingService::class)
            ->restorePublishedRevision(
                $actor, $page->company_id, $page->getKey(),
                $targetRevisionId, $this->loadedPublishedRevisionId, $this->reason
            );
        $this->reason = '';
        Notification::make()->title('Published snapshot restored')->success()->send();
    }

    private function checkEditorSnapshot(SitePage $page): void
    {
        // IDs from mutable select controls must match the server-loaded snapshot.
        abort_unless($this->loadedPageId === $page->getKey()
            && $this->loadedCompanyId === $page->company_id, 409);
    }

    private function authorizeSelectedPage(string $ability): array
    {
        abort_unless(static::canAccess(), 403);
        $actor = Filament::auth()->user();
        $page = $this->currentPage();
        abort_unless($page, 404);
        Gate::forUser($actor)->authorize($ability, $page);

        return [$actor, $page];
    }
}
