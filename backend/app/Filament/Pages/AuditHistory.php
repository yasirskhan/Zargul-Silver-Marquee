<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\AuditHistoryService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

final class AuditHistory extends Page
{
    protected string $view = 'filament.pages.audit-history';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit history';

    protected static ?string $slug = 'audit-history';

    private const PAGE_SIZE = 25;

    #[Url(as: 'company')]
    public ?string $companyId = null;

    public array $filters = [
        'action' => '', 'entityType' => '', 'entityId' => '',
        'fromDate' => '', 'toDate' => '',
    ];

    #[Locked]
    public array $entries = [];

    #[Locked]
    public bool $hasMore = false;

    #[Locked]
    public int $offset = 0;

    #[Locked]
    public ?string $loadedCompanyId = null;

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            && static::visibleCompanies($actor) !== [];
    }

    private static function visibleCompanies(User $actor): array
    {
        $options = [];

        foreach (CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())
            ->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->orderBy('company_id')->get() as $membership) {
            $options[$membership->company_id] = $membership->company->name;
        }

        return $options;
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            ? static::visibleCompanies($actor) : [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $options = $this->companyOptions();
        $requested = $this->companyId ?? request()->query('company');
        // Server-authorized company selection for private search navigation.
        abort_unless($requested === null || (is_string($requested)
            && array_key_exists($requested, $options)), 403);
        $this->companyId = $requested ?? array_key_first($options);
        $this->applyFilters();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
    }

    public function updatedCompanyId(): void
    {
        $this->offset = 0;
        $this->loadEntries();
    }

    public function applyFilters(): void
    {
        $this->offset = 0;
        $this->loadEntries();
    }

    public function nextPage(): void
    {
        $this->authorizeLoadedCompany();
        if (! $this->hasMore || $this->offset > 5000 - self::PAGE_SIZE) {
            return;
        }
        $this->offset += self::PAGE_SIZE;
        $this->loadEntries();
    }

    public function previousPage(): void
    {
        $this->authorizeLoadedCompany();
        $this->offset = max(0, $this->offset - self::PAGE_SIZE);
        $this->loadEntries();
    }

    private function authorizeLoadedCompany(): void
    {
        abort_unless($this->companyId !== null
            && $this->companyId === $this->loadedCompanyId
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
    }

    private function loadEntries(): void
    {
        $this->entries = [];
        $this->hasMore = false;
        $this->loadedCompanyId = null;

        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && static::canAccess()
            && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);

        $filters = [];
        foreach ($this->filters as $name => $value) {
            if ($value !== '') {
                $filters[$name] = $value;
            }
        }

        try {
            $result = app(AuditHistoryService::class)->search(
                $actor, $this->companyId, $filters, self::PAGE_SIZE, $this->offset
            );
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'filters' => 'Use exact action/entity identifiers and valid dates (YYYY-MM-DD).',
            ]);
        }

        $this->entries = $result['entries'];
        $this->hasMore = $result['hasMore'];
        $this->loadedCompanyId = $this->companyId;
    }
}
