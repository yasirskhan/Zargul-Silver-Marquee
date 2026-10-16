<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\InternalQuickSearchService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

final class InternalQuickSearch extends Page
{
    protected string $view = 'filament.pages.internal-quick-search';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;
    protected static ?string $navigationLabel = 'Internal search';
    protected static ?string $slug = 'internal-search';

    public ?string $companyId = null;
    public string $searchTerm = '';

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public array $results = [];

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            && static::companiesFor($actor) !== [];
    }

    private static function companiesFor(User $actor): array
    {
        return CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin', 'manager', 'operations'])
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->orderBy('company_id')->get()
            ->mapWithKeys(fn ($m) => [$m->company_id => $m->company->name])->all();
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            ? static::companiesFor($actor) : [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->companyId = array_key_first($this->companyOptions());
        $this->loadedCompanyId = $this->companyId;
    }

    public function hydrate(): void
    {
        $this->authorizeCurrent();
    }

    private function authorizeCurrent(): User
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    public function updatedCompanyId(): void
    {
        $this->authorizeCurrent();
        $this->searchTerm = '';
        $this->results = [];
        $this->loadedCompanyId = $this->companyId;
    }

    public function runSearch(): void
    {
        $actor = $this->authorizeCurrent();
        abort_unless($this->loadedCompanyId === $this->companyId, 403);
        $this->validate(['searchTerm' => ['required', 'string', 'min:3', 'max:80']]);
        try {
            $this->results = app(InternalQuickSearchService::class)
                ->search($actor, $this->companyId, $this->searchTerm);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['searchTerm' => $error->getMessage()]);
        }
    }
}
