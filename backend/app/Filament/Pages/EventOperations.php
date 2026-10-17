<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\TenantAccess;
use App\Services\VenueEventOperationsService;
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

final class EventOperations extends Page
{
    protected string $view = 'filament.pages.event-operations';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;
    protected static ?string $navigationLabel = 'Event check-in & closeout';
    protected static ?string $slug = 'event-operations';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public array $rows = [];

    #[Locked]
    public array $checklist = [];

    #[Locked]
    public string $fulfillmentRequestKey = '';

    public string $taskReason = '';

    #[Locked]
    public ?string $selectedReservationId = null;

    #[Locked]
    public int $expectedRevision = 0;

    public string $startClock = '';
    public string $endClock = '';
    public string $reason = '';

    private static function currentUserAllowed(User $user): bool
    {
        return DB::table('users')->where('id', $user->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }

    private static function companiesFor(User $actor): array
    {
        $access = app(TenantAccess::class);
        $companies = [];
        foreach (CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin', 'manager', 'reception', 'operations'])
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->orderBy('company_id')->get() as $member) {
            if (Hall::query()->where('company_id', $member->company_id)
                ->where('is_active', true)->get()
                ->contains(fn (Hall $hall) => $access->canViewHall($actor, $hall))) {
                $companies[$member->company_id] = $member->company->name;
            }
        }
        return $companies;
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && self::currentUserAllowed($actor)
            && self::companiesFor($actor) !== [];
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && self::currentUserAllowed($actor)
            ? self::companiesFor($actor) : [];
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

    public function recordingEnabled(): bool
    {
        return config('event_controls.recording_enabled') === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $companies = $this->companyOptions();
        $requested = $this->companyId ?? request()->query('company');
        abort_unless($requested === null || (is_string($requested)
            && array_key_exists($requested, $companies)), 403);
        $this->companyId = $requested ?? array_key_first($companies);
        $this->fulfillmentRequestKey = (string) Str::uuid();
        $this->refresh();
    }

    public function hydrate(): void
    {
        $this->authorizeLoaded();
    }

    public function updatedCompanyId(): void
    {
        $this->authorizeCompany();
        $this->selectedReservationId = null;
        $this->expectedRevision = 0;
        $this->checklist = [];
        $this->taskReason = '';
        $this->fulfillmentRequestKey = (string) Str::uuid();
        $this->startClock = '';
        $this->endClock = '';
        $this->reason = '';
        $this->refresh();
    }

    public function refresh(): void
    {
        $actor = $this->authorizeCompany();
        $this->rows = app(VenueEventOperationsService::class)
            ->worklist($actor, $this->companyId, 30);
        $this->loadedCompanyId = $this->companyId;
    }

    public function selectReservation(string $reservationId): void
    {
        $this->authorizeLoaded();
        abort_unless(Str::isUuid($reservationId), 403);
        $row = collect($this->rows)->firstWhere('reservationId', $reservationId);
        abort_unless(is_array($row), 403);
        $this->selectedReservationId = $reservationId;
        $this->checklist = app(VenueEventOperationsService::class)
            ->checklist($this->authorizeLoaded(), $this->companyId, $reservationId);
        $this->taskReason = '';
        $this->expectedRevision = $row['revision'];
        $this->startClock = $row['start'] ?? '';
        $this->endClock = $row['end'] ?? '';
        $this->reason = '';
    }

    public function selectedRow(): ?array
    {
        $this->authorizeLoaded();
        return $this->selectedReservationId === null ? null
            : collect($this->rows)->firstWhere('reservationId', $this->selectedReservationId);
    }

    public function fulfillSelectedTask(string $taskId): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->recordingEnabled()
            && $this->selectedReservationId !== null && Str::isUuid($taskId), 403);
        $selected = $this->selectedRow();
        abort_unless(is_array($selected) && $selected['status'] === 'in_progress', 403);
        $task = collect($this->checklist)->firstWhere('taskId', $taskId);
        abort_unless(is_array($task) && $task['planningStatus'] === 'reviewed'
            && ! $task['fulfilled'], 403);
        $this->validate(['taskReason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            app(VenueEventOperationsService::class)->fulfillTask(
                $actor, $this->companyId, $this->selectedReservationId,
                $taskId, $this->fulfillmentRequestKey, $this->taskReason
            );
        } catch (ConflictHttpException | InvalidArgumentException $error) {
            throw ValidationException::withMessages(['taskReason' => $error->getMessage()]);
        }
        $id = $this->selectedReservationId;
        $this->fulfillmentRequestKey = (string) Str::uuid();
        $this->refresh();
        $this->selectReservation($id);
    }

    public function startEvent(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->recordingEnabled() && $this->selectedReservationId !== null, 403);
        $this->validate([
            'startClock' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $selected = $this->selectedRow();
        abort_unless(is_array($selected) && $selected['status'] === 'not_started', 403);
        try {
            app(VenueEventOperationsService::class)->start(
                $actor, $this->companyId, $selected['reservationId'],
                (string) Str::uuid(), $this->startClock, $this->reason
            );
        } catch (ConflictHttpException | InvalidArgumentException $error) {
            throw ValidationException::withMessages(['reason' => $error->getMessage()]);
        }
        $id = $selected['reservationId'];
        $this->refresh();
        $this->selectReservation($id);
    }

    public function finishEvent(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->recordingEnabled() && $this->selectedReservationId !== null, 403);
        $this->validate([
            'endClock' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $selected = $this->selectedRow();
        abort_unless(is_array($selected) && $selected['status'] === 'in_progress'
            && $selected['revision'] === $this->expectedRevision, 403);
        try {
            app(VenueEventOperationsService::class)->finish(
                $actor, $this->companyId, $selected['reservationId'],
                $this->expectedRevision, $this->endClock, $this->reason
            );
        } catch (ConflictHttpException | InvalidArgumentException $error) {
            throw ValidationException::withMessages(['reason' => $error->getMessage()]);
        }
        $id = $selected['reservationId'];
        $this->refresh();
        $this->selectReservation($id);
    }
}
