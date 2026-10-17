<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\TenantAccess;
use App\Services\VenueReservationService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class VenueReservations extends Page
{
    protected string $view = 'filament.pages.venue-reservations';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;
    protected static ?string $navigationLabel = 'Reservations';
    protected static ?string $slug = 'reservations';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    #[Url(as: 'proposal')]
    public string $draftId = '';

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public array $reservations = [];

    #[Locked]
    public ?string $selectedReservationId = null;

    #[Locked]
    public int $selectedRevision = 0;

    #[Locked]
    public string $reservationRequestKey = '';

    #[Locked]
    public string $claimRequestKey = '';

    public string $reason = '';
    public string $newDate = '';
    public string $newProgram = 'evening';

    private static function currentUserAllowed(User $user): bool
    {
        return DB::table('users')->where('id', $user->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User || ! self::currentUserAllowed($actor)) {
            return false;
        }
        return static::companiesFor($actor) !== [];
    }

    private static function companiesFor(User $user): array
    {
        $access = app(TenantAccess::class);
        $companies = [];
        foreach (CompanyMembership::query()->with('company')->where('user_id', $user->getKey())
            ->where('is_active', true)->whereIn('role', ['owner', 'admin', 'manager', 'reception'])
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->orderBy('company_id')->get() as $member) {
            $hasHall = Hall::query()->where('company_id', $member->company_id)
                ->where('is_active', true)->get()
                ->contains(fn (Hall $hall) => $access->canManageBookings($user, $hall));
            if ($hasHall) {
                $companies[$member->company_id] = $member->company->name;
            }
        }
        return $companies;
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && self::currentUserAllowed($actor)
            ? self::companiesFor($actor) : [];
    }

    public function hallOptions(): array
    {
        $actor = $this->authorizeCompany();
        $options = [];
        foreach (Hall::query()->where('company_id', $this->companyId)
            ->where('is_active', true)->orderBy('name')->get() as $hall) {
            if (app(TenantAccess::class)->canManageBookings($actor, $hall)) {
                $options[$hall->id] = $hall->name;
            }
        }
        return $options;
    }

    public function proposalOptions(): array
    {
        $this->authorizeCompany();
        $halls = array_keys($this->hallOptions());
        $query = DB::table('booking_drafts')->where('company_id', $this->companyId)
            ->where('status', 'under_review')->whereIn('hall_id', $halls);
        $rows = (clone $query)->orderByDesc('event_date')->limit(50)
            ->get(['id', 'hall_id', 'event_date', 'program', 'requested_guests']);
        $names = $this->hallOptions();
        $options = $rows->mapWithKeys(fn ($row) => [$row->id =>
            ($names[$row->hall_id] ?? 'Hall').' | '.$row->event_date.' '.ucfirst($row->program)
            .' | '.$row->requested_guests.' guests'])->all();
        if ($this->draftId !== '' && Str::isUuid($this->draftId)
            && ! array_key_exists($this->draftId, $options)) {
            $row = (clone $query)->where('id', $this->draftId)
                ->first(['id', 'hall_id', 'event_date', 'program', 'requested_guests']);
            if ($row) {
                $options[$row->id] = ($names[$row->hall_id] ?? 'Hall').' | '
                    .$row->event_date.' '.ucfirst($row->program)
                    .' | '.$row->requested_guests.' guests';
            }
        }
        return $options;
    }

    private function authorizeCompany(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && static::canAccess()
            && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        return $actor;
    }

    private function authorizeLoaded(): User
    {
        $actor = $this->authorizeCompany();
        abort_unless($this->loadedCompanyId === $this->companyId, 403);
        return $actor;
    }

    private function newKeys(): void
    {
        $this->reservationRequestKey = (string) Str::uuid();
        $this->claimRequestKey = (string) Str::uuid();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = $this->companyOptions();
        $requested = $this->companyId ?? request()->query('company');
        abort_unless($requested === null || (is_string($requested)
            && array_key_exists($requested, $companies)), 403);
        $this->companyId = $requested ?? array_key_first($companies);
        $this->newKeys();
        $this->refresh();
        if ($this->draftId !== '') {
            abort_unless(Str::isUuid($this->draftId)
                && array_key_exists($this->draftId, $this->proposalOptions()), 403);
        }
    }

    public function hydrate(): void
    {
        $this->authorizeLoaded();
    }

    public function enabled(): bool
    {
        return config('booking_controls.confirmation_enabled') === true;
    }

    public function updatedCompanyId(): void
    {
        $this->authorizeCompany();
        $this->draftId = '';
        $this->selectedReservationId = null;
        $this->selectedRevision = 0;
        $this->reason = '';
        $this->newDate = '';
        $this->newProgram = 'evening';
        $this->newKeys();
        $this->refresh();
    }

    public function refresh(): void
    {
        $actor = $this->authorizeCompany();
        $this->reservations = app(VenueReservationService::class)
            ->list($actor, $this->companyId, 30);
        $this->loadedCompanyId = $this->companyId;
    }

    public function confirmSelected(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->enabled(), 403);
        $this->validate(['draftId' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500']]);
        abort_unless(array_key_exists($this->draftId, $this->proposalOptions()), 403);
        $draft = DB::table('booking_drafts')->where('company_id', $this->companyId)
            ->where('id', $this->draftId)->first();
        abort_unless($draft !== null, 403);
        try {
            $result = app(VenueReservationService::class)->confirm(
                $actor, $this->companyId, $this->draftId,
                $this->reservationRequestKey, $this->claimRequestKey,
                (int) $draft->revision, $this->reason
            );
        } catch (ConflictHttpException | InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }
        $this->draftId = '';
        $this->reason = '';
        $this->newKeys();
        $this->refresh();
        $this->selectReservation($result['id']);
    }

    public function selectReservation(string $reservationId): void
    {
        $this->authorizeLoaded();
        abort_unless(Str::isUuid($reservationId), 403);
        $selected = collect($this->reservations)
            ->first(fn (array $row) => $row['id'] === $reservationId);
        abort_unless(is_array($selected), 403);
        $this->selectedReservationId = $reservationId;
        $this->selectedRevision = $selected['revision'];
        $this->newDate = $selected['date'];
        $this->newProgram = $selected['program'];
        $this->reason = '';
        $this->claimRequestKey = (string) Str::uuid();
    }

    public function rescheduleSelected(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->enabled() && $this->selectedReservationId !== null, 403);
        $this->validate([
            'newDate' => ['required', 'date_format:Y-m-d'],
            'newProgram' => ['required', 'in:morning,noon,evening'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        try {
            app(VenueReservationService::class)->reschedule(
                $actor, $this->companyId, $this->selectedReservationId,
                $this->newDate, $this->newProgram,
                $this->claimRequestKey, $this->selectedRevision, $this->reason
            );
        } catch (ConflictHttpException | InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }
        $id = $this->selectedReservationId;
        $this->refresh();
        $this->selectReservation($id);
    }

    public function cancelSelected(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->enabled() && $this->selectedReservationId !== null, 403);
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            app(VenueReservationService::class)->cancel(
                $actor, $this->companyId, $this->selectedReservationId,
                $this->selectedRevision, $this->reason
            );
        } catch (ConflictHttpException | InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }
        $this->selectedReservationId = null;
        $this->selectedRevision = 0;
        $this->reason = '';
        $this->refresh();
    }
}
