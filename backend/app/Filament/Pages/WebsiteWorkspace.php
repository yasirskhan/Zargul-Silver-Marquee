<?php

namespace App\Filament\Pages;

use App\Models\SitePage;
use App\Models\User;
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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WebsiteWorkspace extends Page
{
    protected string $view = 'filament.pages.website-workspace';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Website';

    protected static ?string $slug = 'website';

    public ?string $companyId = null;

    public ?string $pageId = null;

    /** Typed sections are an editable draft working copy, never live published content. */
    public array $sections = [];

    public string $reason = '';

    #[Locked]
    public ?string $loadedPageId = null;

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public int $expectedRevision = 0;

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
        $this->loadedPageId = null;
        $this->loadedCompanyId = null;

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

    public function moveSection(int $index, string $direction): void
    {
        $this->authorizeSelectedPage('update');
        abort_unless(in_array($direction, ['up', 'down'], true), 422);
        $target = $index + ($direction === 'up' ? -1 : 1);
        abort_unless(isset($this->sections[$index], $this->sections[$target]), 422);
        [$this->sections[$index], $this->sections[$target]] =
            [$this->sections[$target], $this->sections[$index]];
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

        app(SitePagePublishingService::class)->publish(
            $actor, $page->company_id, $page->getKey(), $this->expectedRevision, $this->reason
        );
        $this->reason = '';
        Notification::make()->title('Published snapshot updated')->success()->send();
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
