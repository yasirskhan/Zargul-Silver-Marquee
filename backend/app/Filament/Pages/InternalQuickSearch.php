<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\InternalQuickSearchService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

final class InternalQuickSearch extends Page
{
    protected string $view = 'filament.pages.internal-quick-search';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;
    protected static ?string $navigationLabel = 'Internal search';
    protected static ?string $slug = 'internal-search';

    #[Url(as: 'company')]
    public ?string $companyId = null;
    public string $searchTerm = '';
    public string $searchKind = 'all';

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public array $results = [];

    private static function currentAccountAllowed(User $actor): bool
    {
        return DB::table('users')->where('id', $actor->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && self::currentAccountAllowed($actor)
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
        return $actor instanceof User && self::currentAccountAllowed($actor)
            ? static::companiesFor($actor) : [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = $this->companyOptions();
        $requested = $this->companyId ?? request()->query('company');
        // A deep link must select only a company the current account can access.
        abort_unless($requested === null || (is_string($requested)
            && array_key_exists($requested, $companies)), 403);
        $this->companyId = $requested ?? array_key_first($companies);
        $this->loadedCompanyId = $this->companyId;
    }

    public function hydrate(): void
    {
        $this->authorizeCurrent();
        // Never replay a prior result snapshot across changes to the search,
        // member role or hall assignment. Only the current server search fills it.
        $this->results = [];
    }

    private function authorizeCurrent(): User
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    public function sourceOptions(): array
    {
        $actor = $this->authorizeCurrent();
        $membership = CompanyMembership::query()->where('company_id', $this->companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)->first();
        if (! $membership) {
            abort(403);
        }
        if ($membership->role === 'operations') {
            return ['all' => 'All permitted records', 'planning' => 'Planning tasks'];
        }
        if ($membership->role === 'manager') {
            return [
                'all' => 'All permitted records', 'planning' => 'Planning tasks',
                'approval' => 'My approval requests',
            ];
        }
        return [
            'all' => 'All permitted records', 'proposal' => 'Booking proposals',
            'planning' => 'Planning tasks', 'approval' => 'Approval requests',
            'finance' => 'Nonbinding finance previews', 'audit' => 'Audit actions',
        ];
    }

    public function updatedCompanyId(): void
    {
        $this->authorizeCurrent();
        $this->searchKind = 'all';
        $this->searchTerm = '';
        $this->results = [];
        $this->loadedCompanyId = $this->companyId;
    }

    public function runSearch(): void
    {
        $actor = $this->authorizeCurrent();
        abort_unless($this->loadedCompanyId === $this->companyId, 403);
        $this->validate([
            'searchTerm' => ['required', 'string', 'min:3', 'max:80'],
            'searchKind' => ['required', 'in:all,proposal,planning,approval,finance,audit'],
        ]);
        try {
            $this->results = app(InternalQuickSearchService::class)
                ->search($actor, $this->companyId, $this->searchTerm, 20, $this->searchKind);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['searchTerm' => $error->getMessage()]);
        }
    }
}
