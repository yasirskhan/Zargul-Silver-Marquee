<?php

namespace App\Filament\Pages;

use App\Models\SitePage;
use App\Models\User;
use App\Services\SiteWorkspaceService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class WebsiteWorkspace extends Page
{
    protected string $view = 'filament.pages.website-workspace';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Website';

    protected static ?string $slug = 'website';

    public ?string $companyId = null;

    public ?string $pageId = null;

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
    }

    public function hydrate(): void
    {
        // Livewire requests re-check active membership after role revocation.
        abort_unless(static::canAccess(), 403);
    }

    public function updatedCompanyId(): void
    {
        $this->pageId = array_key_first($this->pageOptions());
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
}
