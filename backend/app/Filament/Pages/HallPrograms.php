<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallProgramRulesService;
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
        $this->rules = $this->emptyRules();
        $this->currencyCode = '';
        $this->reason = '';
        $this->loadedCompanyId = null;
        $this->loadedHallId = null;
        $this->expectedRevision = 0;

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
        $this->reason = '';
        Notification::make()->title('Hall program rules saved with audit history')->success()->send();
    }
}
