<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\HallOccupancyCalendarService;
use App\Services\TenantAccess;
use BackedEnum;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class HallCalendar extends Page
{
    protected string $view = 'filament.pages.hall-calendar';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Hall calendar';

    protected static ?string $slug = 'hall-calendar';

    public ?string $companyId = null;

    public ?string $hallId = null;

    public string $viewMode = 'week';

    public string $anchorDate = '';

    #[Locked]
    public array $projection = [];

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public ?string $loadedHallId = null;

    private static function currentAccountAllowed(User $actor): bool
    {
        return DB::table('users')->where('id', $actor->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && self::currentAccountAllowed($actor)
            && static::visibleCompanies($actor) !== [];
    }

    /** Only tenants with an active hall visible to this staff member are listed. */
    private static function visibleCompanies(User $actor): array
    {
        $access = app(TenantAccess::class);
        $options = [];

        foreach (CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))->get() as $membership) {
            $hasHall = Hall::query()->where('company_id', $membership->company_id)
                ->where('is_active', true)->get()
                ->contains(fn (Hall $hall): bool => $access->canViewHall($actor, $hall));
            if ($hasHall) {
                $options[$membership->company_id] = $membership->company->name;
            }
        }

        return $options;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->anchorDate = now()->toDateString();
        $this->companyId = array_key_first($this->companyOptions());
        $this->hallId = array_key_first($this->hallOptions());
        $this->reloadCalendar();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->hallId !== null) {
            abort_unless(array_key_exists($this->hallId, $this->hallOptions()), 403);
        }
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && self::currentAccountAllowed($actor)
            ? static::visibleCompanies($actor) : [];
    }

    public function hallOptions(): array
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User || $this->companyId === null
            || ! array_key_exists($this->companyId, $this->companyOptions())) {
            return [];
        }

        $options = [];
        $access = app(TenantAccess::class);
        foreach (Hall::query()->where('company_id', $this->companyId)
            ->where('is_active', true)->orderBy('name')->get() as $hall) {
            if ($access->canViewHall($actor, $hall)) {
                $options[$hall->id] = $hall->name;
            }
        }

        return $options;
    }

    /**
     * The occupancy table is available to scoped operations staff, whereas
     * internal proposal review must remain owner/admin-only for this company.
     */
    public function canOpenDraftProposals(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && self::currentAccountAllowed($actor)
            && $this->companyId !== null && $this->hallId !== null
            && $this->loadedCompanyId === $this->companyId
            && $this->loadedHallId === $this->hallId
            && array_key_exists($this->hallId, $this->hallOptions())
            && CompanyMembership::query()->where('company_id', $this->companyId)
                ->where('user_id', $actor->getKey())->where('is_active', true)
                ->whereIn('role', ['owner', 'admin'])->exists();
    }

    public function updatedCompanyId(): void
    {
        abort_unless(array_key_exists((string) $this->companyId, $this->companyOptions()), 403);
        $this->hallId = array_key_first($this->hallOptions());
        $this->reloadCalendar();
    }

    public function updatedHallId(): void
    {
        $this->reloadCalendar();
    }

    public function updatedViewMode(): void
    {
        $this->reloadCalendar();
    }

    public function stepPeriod(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 403);
        $anchor = $this->validAnchor();

        // Starting from the first of a month avoids 31st-to-February overflow.
        $basis = $this->viewMode === 'month'
            ? $anchor->modify('first day of this month')
            : $anchor;
        $interval = match ($this->viewMode) {
            'day' => 'day', 'week' => 'week', 'month' => 'month',
            default => throw ValidationException::withMessages(['viewMode' => 'Select day, week or month.']),
        };
        $this->anchorDate = $basis->modify(($direction > 0 ? '+1 ' : '-1 ').$interval)
            ->format('Y-m-d');
        $this->reloadCalendar();
    }

    public function reloadCalendar(): void
    {
        $this->projection = [];
        $this->loadedCompanyId = null;
        $this->loadedHallId = null;

        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $actor->hasVerifiedEmail()
            && $this->companyId !== null && $this->hallId !== null
            && array_key_exists($this->hallId, $this->hallOptions()), 403);

        $anchor = $this->validAnchor();
        [$start, $end] = match ($this->viewMode) {
            'day' => [$anchor, $anchor],
            'week' => [
                $anchor->modify('monday this week'),
                $anchor->modify('monday this week')->modify('+6 days'),
            ],
            'month' => [
                $anchor->modify('first day of this month'),
                $anchor->modify('last day of this month'),
            ],
            default => throw ValidationException::withMessages(['viewMode' => 'Select day, week or month.']),
        };

        $this->projection = app(HallOccupancyCalendarService::class)->forHall(
            $actor, $this->companyId, $this->hallId,
            $start->format('Y-m-d'), $end->format('Y-m-d')
        );
        $this->loadedCompanyId = $this->companyId;
        $this->loadedHallId = $this->hallId;
    }

    private function validAnchor(): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->anchorDate);
        if ($date === false || $date->format('Y-m-d') !== $this->anchorDate) {
            throw ValidationException::withMessages(['anchorDate' => 'Choose a valid date.']);
        }

        return $date;
    }
}
