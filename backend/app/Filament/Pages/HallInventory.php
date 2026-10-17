<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallInventoryCatalogService;
use App\Services\HallInventoryStocktakeService;
use App\Services\TenantAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class HallInventory extends Page
{
    protected string $view = 'filament.pages.hall-inventory';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;
    protected static ?string $navigationLabel = 'Hall inventory';
    protected static ?string $slug = 'hall-inventory';

    #[Url(as: 'company')]
    public ?string $companyId = null;
    public ?string $hallId = null;

    #[Locked]
    public array $catalog = [];
    #[Locked]
    public string $createRequestId = '';
    #[Locked]
    public string $countRequestId = '';
    #[Locked]
    public ?string $lastCountReference = null;

    public string $itemName = '';
    public string $itemCategory = 'Crockery';
    public string $itemUnit = 'piece';
    public string $minimumQuantity = '0';
    public string $createReason = '';
    public string $auditKind = 'ad_hoc';
    public string $auditReason = '';
    public array $countRows = [];

    private static function userAllowed(User $actor): bool
    {
        return DB::table('users')->where('id', $actor->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        if (! config('inventory_controls.catalog_enabled')
            || ! $actor instanceof User || ! self::userAllowed($actor)) {
            return false;
        }
        foreach (static::companiesFor($actor) as $id => $name) {
            if (static::authorizedHallOptions($actor, $id) !== []) {
                return true;
            }
        }
        return false;
    }

    private static function companiesFor(User $actor): array
    {
        return CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin', 'manager'])
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->get()->mapWithKeys(fn ($membership) => [
                $membership->company_id => $membership->company->name,
            ])->all();
    }

    private static function authorizedHallOptions(User $actor, string $companyId): array
    {
        $access = app(TenantAccess::class);
        $halls = [];
        foreach (Hall::query()->where('company_id', $companyId)
            ->where('is_active', true)->orderBy('name')->limit(100)->get() as $hall) {
            if ($access->canManageHallInventory($actor, $hall)) {
                $halls[$hall->id] = $hall->name;
            }
        }
        return $halls;
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && self::userAllowed($actor)
            ? static::companiesFor($actor) : [];
    }

    public function hallOptions(): array
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions())
            ? static::authorizedHallOptions($actor, $this->companyId) : [];
    }

    private function authorizeSelection(): User
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && $this->hallId !== null && array_key_exists($this->hallId, $this->hallOptions()), 403);
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = $this->companyOptions();
        abort_unless($this->companyId === null
            || array_key_exists($this->companyId, $companies), 403);
        if ($this->companyId === null) {
            foreach ($companies as $id => $label) {
                if (static::authorizedHallOptions(Filament::auth()->user(), $id) !== []) {
                    $this->companyId = $id;
                    break;
                }
            }
        }
        $this->hallId = array_key_first($this->hallOptions());
        $this->createRequestId = (string) Str::uuid();
        $this->countRequestId = (string) Str::uuid();
        $this->reloadCatalog();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
    }

    public function updatedCompanyId(): void
    {
        abort_unless($this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        $this->hallId = array_key_first($this->hallOptions());
        $this->resetForms();
        $this->reloadCatalog();
    }

    public function updatedHallId(): void
    {
        $this->resetForms();
        $this->reloadCatalog();
    }

    private function resetForms(): void
    {
        $this->itemName = '';
        $this->itemCategory = 'Crockery';
        $this->itemUnit = 'piece';
        $this->minimumQuantity = '0';
        $this->createReason = '';
        $this->auditReason = '';
        $this->countRows = [];
        $this->lastCountReference = null;
        $this->createRequestId = (string) Str::uuid();
        $this->countRequestId = (string) Str::uuid();
    }

    public function reloadCatalog(): void
    {
        $this->catalog = [];
        $this->authorizeSelection();
        $this->catalog = DB::table('hall_inventory_items')
            ->where('company_id', $this->companyId)->where('hall_id', $this->hallId)
            ->where('is_active', true)->orderBy('name')->limit(100)
            ->get(['id', 'name', 'category', 'unit', 'minimum_quantity'])
            ->map(fn ($item) => [
                'id' => $item->id, 'name' => $item->name, 'category' => $item->category,
                'unit' => $item->unit, 'minimumQuantity' => (int) $item->minimum_quantity,
            ])->all();
    }

    public function createItem(): void
    {
        $actor = $this->authorizeSelection();
        $this->validate([
            'itemName' => 'required|string|min:1|max:160',
            'itemCategory' => 'required|string|max:80',
            'itemUnit' => 'required|in:piece,set,pair,kg,liter',
            'minimumQuantity' => ['required', 'regex:/\\A[0-9]{1,9}\\z/'],
            'createReason' => 'required|string|min:3|max:500',
        ]);
        try {
            app(HallInventoryCatalogService::class)->create(
                $actor, $this->companyId, $this->hallId, $this->createRequestId,
                $this->itemName, $this->itemCategory, $this->itemUnit,
                (int) $this->minimumQuantity, $this->createReason,
            );
        } catch (InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['itemName' => $e->getMessage()]);
        }
        $this->itemName = '';
        $this->createReason = '';
        $this->minimumQuantity = '0';
        $this->createRequestId = (string) Str::uuid();
        $this->reloadCatalog();
    }

    public function addCountRow(): void
    {
        $this->authorizeSelection();
        abort_unless(config('inventory_controls.stocktakes_enabled'), 403);
        abort_unless(count($this->countRows) < 100, 403);
        $this->countRows[] = [
            'key' => (string) Str::uuid(), 'itemId' => '',
            'ownership' => 'owned', 'usableCount' => '', 'damagedCount' => '0',
        ];
    }

    public function removeCountRow(string $rowKey): void
    {
        $this->authorizeSelection();
        $this->countRows = array_values(array_filter(
            $this->countRows, fn ($row) => ($row['key'] ?? '') !== $rowKey));
    }

    public function submitCount(): void
    {
        $actor = $this->authorizeSelection();
        abort_unless(config('inventory_controls.stocktakes_enabled'), 403);
        if (count($this->countRows) === 0 || count($this->countRows) > 100) {
            throw ValidationException::withMessages(['countRows' => 'Add between 1 and 100 item rows.']);
        }
        $lines = [];
        foreach ($this->countRows as $index => $row) {
            foreach (['usableCount', 'damagedCount'] as $field) {
                if (! is_string($row[$field] ?? null)
                    || ! preg_match('/\\A[0-9]{1,9}\\z/D', $row[$field])) {
                    throw ValidationException::withMessages([
                        'countRows' => 'Enter a nonnegative whole number for each item count.',
                    ]);
                }
            }
            $lines[] = [
                'itemId' => $row['itemId'] ?? '',
                'ownership' => $row['ownership'] ?? '',
                'usableCount' => (int) $row['usableCount'],
                'damagedCount' => (int) $row['damagedCount'],
            ];
        }
        try {
            $this->lastCountReference = app(HallInventoryStocktakeService::class)->submit(
                $actor, $this->companyId, $this->hallId, $this->countRequestId,
                $this->auditKind, $lines, $this->auditReason);
        } catch (InvalidArgumentException|ConflictHttpException $e) {
            throw ValidationException::withMessages(['countRows' => $e->getMessage()]);
        }
        $this->countRows = [];
        $this->auditReason = '';
        $this->countRequestId = (string) Str::uuid();
    }
}
