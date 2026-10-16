<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallProgramRulesService;
use App\Services\HallOvertimePreviewService;
use App\Services\HallAddonCatalogService;
use App\Services\HallSeatingPriceService;
use App\Services\TenantAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class HallPrograms extends Page
{
    protected string $view = 'filament.pages.hall-programs';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Hall programs';

    protected static ?string $slug = 'hall-programs';

    public ?string $companyId = null;

    public ?string $hallId = null;

    public array $rules = [];

    public string $currencyCode = '';

    public string $reason = '';

    public string $overtimeProgram = 'morning';
    public string $overtimeObservedEnd = '';

    #[Locked]
    public ?array $overtimePreview = null;

    /** Separate approved seating snapshot and optimistic revision token. */
    public array $seating = [];

    public string $seatingCurrencyCode = '';

    public string $seatingReason = '';

    /** No add-ons or prices exist until explicitly approved by the owner. */
    public array $addons = [];

    public string $addonCurrencyCode = '';

    public string $addonReason = '';

    #[Locked]
    public int $expectedAddonRevision = 0;

    #[Locked]
    public int $expectedSeatingRevision = 0;

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public ?string $loadedHallId = null;

    #[Locked]
    public int $expectedRevision = 0;

    private const PROGRAMS = ['morning', 'noon', 'evening'];

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            && static::availableCompaniesFor($actor) !== [];
    }

    /**
     * Visible companies must have at least one hall the current actor may read.
     * Never trust a Livewire company or hall selector as an authorization grant.
     */
    private static function availableCompaniesFor(User $actor): array
    {
        if (! $actor->is_active || ! $actor->hasVerifiedEmail()) {
            return [];
        }

        $memberships = CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))->get();

        $access = app(TenantAccess::class);
        $options = [];
        foreach ($memberships as $membership) {
            $visible = Hall::query()->where('company_id', $membership->company_id)
                ->where('is_active', true)->get()
                ->contains(fn (Hall $hall): bool => $access->canViewHall($actor, $hall));
            if ($visible) {
                $options[$membership->company_id] = $membership->company->name;
            }
        }

        return $options;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->companyId = array_key_first($this->companyOptions());
        $this->hallId = array_key_first($this->hallOptions());
        $this->reloadRules();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User ? static::availableCompaniesFor($actor) : [];
    }

    public function hallOptions(): array
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User || ! $this->companyId
            || ! array_key_exists($this->companyId, $this->companyOptions())) {
            return [];
        }

        $access = app(TenantAccess::class);
        $options = [];
        foreach (Hall::query()->where('company_id', $this->companyId)
            ->where('is_active', true)->orderBy('name')->get() as $hall) {
            if ($access->canViewHall($actor, $hall)) {
                $options[$hall->id] = $hall->name;
            }
        }

        return $options;
    }

    public function updatedCompanyId(): void
    {
        $this->hallId = array_key_first($this->hallOptions());
        $this->reloadRules();
    }

    public function updatedHallId(): void
    {
        $this->reloadRules();
    }

    private function selectedHall(): ?Hall
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User || ! $actor->hasVerifiedEmail()
            || ! $this->companyId || ! $this->hallId
            || ! array_key_exists($this->hallId, $this->hallOptions())) {
            return null;
        }

        $hall = Hall::query()->where('company_id', $this->companyId)
            ->where('id', $this->hallId)->where('is_active', true)->first();

        return $hall && app(TenantAccess::class)->canViewHall($actor, $hall) ? $hall : null;
    }

    public function canConfigureSelectedHall(): bool
    {
        $actor = Filament::auth()->user();
        $hall = $this->selectedHall();

        return $actor instanceof User && $hall && $actor->hasVerifiedEmail()
            && app(TenantAccess::class)->canConfigureHall($actor, $hall);
    }

    private function emptyRules(): array
    {
        $rules = [];
        foreach (self::PROGRAMS as $program) {
            $rules[$program] = [
                'start' => '', 'end' => '', 'setupMinutes' => '',
                'cleaningMinutes' => '', 'graceMinutes' => '',
                'overtimeIntervalMinutes' => '', 'overtimeRateMinor' => '',
                'rentalRateMinor' => '',
            ];
        }

        return $rules;
    }

    /** Reload is intentional: it discards unsaved edits and the old revision token. */
    public function reloadRules(): void
    {
        $this->overtimeProgram = 'morning';
        $this->overtimeObservedEnd = '';
        $this->overtimePreview = null;
        $this->rules = $this->emptyRules();
        $this->currencyCode = '';
        $this->reason = '';
        $this->loadedCompanyId = null;
        $this->loadedHallId = null;
        $this->expectedRevision = 0;
        $this->expectedSeatingRevision = 0;
        $this->seating = [];
        $this->seatingCurrencyCode = '';
        $this->seatingReason = '';
        $this->addons = [];
        $this->addonCurrencyCode = '';
        $this->addonReason = '';
        $this->expectedAddonRevision = 0;

        if (! $this->hallId) {
            return;
        }

        $hall = $this->selectedHall();
        abort_unless($hall, 403);
        $actor = Filament::auth()->user();
        $current = app(HallProgramRulesService::class)->current($actor, $hall->company_id, $hall->id);
        $this->loadedCompanyId = $hall->company_id;
        $this->loadedHallId = $hall->id;
        if ($current !== null) {
            $this->rules = $current['rules'];
            $this->currencyCode = $current['currencyCode'];
            $this->expectedRevision = $current['revision'];
        }

        // Existing physical hall counts are visible but extra-unit prices start
        // blank until an owner explicitly approves a priced revision.
        $this->seating = [
            'capacity' => (int) $hall->capacity,
            'includedChairs' => (int) $hall->included_chairs,
            'includedTables' => (int) $hall->included_tables,
            'extraChairRateMinor' => '', 'extraTableRateMinor' => '',
        ];
        $seatingCurrent = app(HallSeatingPriceService::class)->current($actor, $hall->company_id, $hall->id);
        if ($seatingCurrent !== null) {
            $this->seating = $seatingCurrent['config'];
            $this->seatingCurrencyCode = $seatingCurrent['currencyCode'];
            $this->expectedSeatingRevision = $seatingCurrent['revision'];
        }
        $addonsCurrent = app(HallAddonCatalogService::class)->current($actor, $hall->company_id, $hall->id);
        if ($addonsCurrent !== null) {
            $this->addons = $addonsCurrent['items'];
            $this->addonCurrencyCode = $addonsCurrent['currencyCode'];
            $this->expectedAddonRevision = $addonsCurrent['revision'];
        }
    }

    public function calculateOvertimePreview(): void
    {
        $actor = Filament::auth()->user();
        $hall = $this->selectedHall();
        abort_unless($actor instanceof User && $hall && $this->expectedRevision > 0
            && $this->loadedCompanyId === $hall->company_id
            && $this->loadedHallId === $hall->id, 403);
        $this->validate([
            'overtimeProgram' => ['required', 'in:morning,noon,evening'],
            'overtimeObservedEnd' => ['required', 'date_format:H:i'],
        ]);
        // Always read saved approved rules, NEVER trust potentially unsaved
        // editable Filament form state for a financial-looking preview.
        try {
            $this->overtimePreview = app(HallOvertimePreviewService::class)->preview(
                $actor, $hall->company_id, $hall->id,
                $this->overtimeProgram, $this->overtimeObservedEnd,
                $this->expectedRevision
            );
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            $this->overtimePreview = null;
            throw ValidationException::withMessages(['overtimeObservedEnd' => $error->getMessage()]);
        }
    }

    public function saveRules(): void
    {
        $actor = Filament::auth()->user();
        $hall = $this->selectedHall();
        abort_unless($actor instanceof User && $hall && $this->canConfigureSelectedHall()
            && $this->loadedCompanyId === $hall->company_id && $this->loadedHallId === $hall->id, 403);

        $validation = [
            'rules' => ['required', 'array', 'size:3'],
            'currencyCode' => ['required', 'regex:/\A[A-Z]{3}\z/'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
        foreach (self::PROGRAMS as $program) {
            $validation["rules.$program"] = ['required', 'array', 'size:8'];
            foreach (['start', 'end'] as $field) {
                $validation["rules.$program.$field"] = ['required', 'date_format:H:i'];
            }
            foreach (['setupMinutes', 'cleaningMinutes', 'graceMinutes', 'overtimeIntervalMinutes',
                'overtimeRateMinor', 'rentalRateMinor'] as $field) {
                $validation["rules.$program.$field"] = ['required', 'integer', 'min:0'];
            }
        }
        $this->validate($validation);

        try {
            $next = app(HallProgramRulesService::class)->configure(
                $actor, $hall->company_id, $hall->id, $this->rules,
                $this->currencyCode, $this->expectedRevision, $this->reason,
            );
        } catch (InvalidArgumentException | ConflictHttpException $exception) {
            throw ValidationException::withMessages([
                'rules' => $exception->getMessage().' Reload the latest rules if another editor saved first.',
            ]);
        }

        $this->expectedRevision = $next;
        $this->overtimePreview = null;
        $this->reason = '';
        Notification::make()->title('Hall program rules saved with audit history')->success()->send();
    }
    /** Owner/admin-only extra seating and capacity settings, separate from session rules. */
    public function saveSeating(): void
    {
        $actor = Filament::auth()->user();
        $hall = $this->selectedHall();
        abort_unless($actor instanceof User && $hall && $this->canConfigureSelectedHall()
            && $this->loadedCompanyId === $hall->company_id && $this->loadedHallId === $hall->id, 403);

        $this->validate([
            'seating' => ['required', 'array', 'size:5'],
            'seating.capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'seating.includedChairs' => ['required', 'integer', 'min:0', 'max:100000'],
            'seating.includedTables' => ['required', 'integer', 'min:0', 'max:100000'],
            'seating.extraChairRateMinor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'seating.extraTableRateMinor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'seatingCurrencyCode' => ['required', 'regex:/\A[A-Z]{3}\z/'],
            'seatingReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $next = app(HallSeatingPriceService::class)->configure(
                $actor, $hall->company_id, $hall->id, $this->seating,
                $this->seatingCurrencyCode, $this->expectedSeatingRevision, $this->seatingReason,
            );
        } catch (InvalidArgumentException | ConflictHttpException $exception) {
            throw ValidationException::withMessages([
                'seating' => $exception->getMessage().' Reload the latest hall settings before retrying.',
            ]);
        }

        $this->expectedSeatingRevision = $next;
        $this->seatingReason = '';
        Notification::make()->title('Hall seating and unit prices saved with audit history')->success()->send();
    }

    private function authorizeAddonEditor(): array
    {
        $actor = Filament::auth()->user();
        $hall = $this->selectedHall();
        abort_unless($actor instanceof User && $hall && $this->canConfigureSelectedHall()
            && $this->loadedCompanyId === $hall->company_id && $this->loadedHallId === $hall->id, 403);

        return [$actor, $hall];
    }

    /** Unsaved local catalog form changes do not touch approved catalog revisions. */
    public function addAddon(): void
    {
        $this->authorizeAddonEditor();
        if (count($this->addons) >= 100) {
            throw ValidationException::withMessages(['addons' => 'A hall may have no more than 100 catalog items.']);
        }
        $this->addons[] = [
            'code' => '', 'name' => '', 'category' => '', 'chargeUnit' => '',
            'rateMinor' => '', 'enabled' => true,
        ];
    }

    public function removeAddon(int $index): void
    {
        $this->authorizeAddonEditor();
        abort_unless(array_is_list($this->addons) && array_key_exists($index, $this->addons), 422);
        array_splice($this->addons, $index, 1);
    }

    public function saveAddons(): void
    {
        [$actor, $hall] = $this->authorizeAddonEditor();
        $this->validate([
            'addons' => ['array', 'max:100'],
            'addons.*.code' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_-]{1,39}\z/'],
            'addons.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'addons.*.category' => ['required', 'in:catering,music,lighting,bridal_entry,stage,photography,other'],
            'addons.*.chargeUnit' => ['required', 'in:flat,per_guest,per_unit'],
            'addons.*.rateMinor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'addons.*.enabled' => ['required', 'boolean'],
            'addonCurrencyCode' => ['required', 'regex:/\A[A-Z]{3}\z/'],
            'addonReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $next = app(HallAddonCatalogService::class)->configure(
                $actor, $hall->company_id, $hall->id, $this->addons,
                $this->addonCurrencyCode, $this->expectedAddonRevision, $this->addonReason,
            );
        } catch (InvalidArgumentException | ConflictHttpException $exception) {
            throw ValidationException::withMessages([
                'addons' => $exception->getMessage().' Reload the latest catalog before retrying.',
            ]);
        }

        $this->expectedAddonRevision = $next;
        $this->addonReason = '';
        Notification::make()->title('Hall add-on catalog saved with audit history')->success()->send();
    }

}
