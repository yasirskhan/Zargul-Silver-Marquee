<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\OperationsPlanningService;
use App\Policies\OperationsPlanningPolicy;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OperationsPlanning extends Page
{
    protected string $view = 'filament.pages.operations-planning';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;
    protected static ?string $navigationLabel = 'Event planning checklist';
    protected static ?string $slug = 'operations-planning';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public array $tasks = [];

    #[Locked]
    public ?string $historyTaskId = null;

    #[Locked]
    public array $historyEvents = [];

    #[Locked]
    public string $createRequestKey = '';

    /** Optional scoped deep link from an internal booking proposal. */
    #[Url(as: 'proposal')]
    public string $bookingId = '';
    public string $taskTitle = '';
    public string $reason = '';
    public string $reviewReason = '';
    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    private static function currentAccountAllowed(User $actor): bool
    {
        return DB::table('users')->where('id', $actor->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && self::currentAccountAllowed($actor)
            && self::companiesFor($actor) !== [];
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
            ? self::companiesFor($actor) : [];
    }

    private function authorizeCompany(): User
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);
        return $actor;
    }

    private function authorizeLoaded(): User
    {
        $actor = $this->authorizeCompany();
        abort_unless($this->loadedCompanyId === $this->companyId, 403);
        return $actor;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $options = $this->companyOptions();
        $requested = $this->companyId ?? request()->query('company');
        // Search deep links select ONLY a current authorized company.
        abort_unless($requested === null || (is_string($requested)
            && array_key_exists($requested, $options)), 403);
        $this->companyId = $requested ?? array_key_first($options);
        $this->createRequestKey = (string) Str::uuid();
        $this->refresh();
        if ($this->bookingId !== '') {
            abort_unless(Str::isUuid($this->bookingId)
                && array_key_exists($this->bookingId, $this->proposalOptions()), 403);
        }
    }

    public function hydrate(): void
    {
        $this->authorizeCompany();
    }

    public function updatedCompanyId(): void
    {
        // Changing tenants invalidates every unsent planning/review note,
        // not just the selected proposal and request idempotency key.
        $this->bookingId = '';
        $this->taskTitle = '';
        $this->reason = '';
        $this->reviewReason = '';
        $this->statusFilter = 'all';
        $this->createRequestKey = (string) Str::uuid();
        $this->refresh();
    }

    public function proposalOptions(): array
    {
        $actor = $this->authorizeLoaded();
        $member = CompanyMembership::query()->where('company_id', $this->companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)->first();
        abort_unless($member !== null, 403);
        $q = DB::table('booking_drafts')->where('company_id', $this->companyId)
            ->whereIn('status', ['draft', 'under_review']);
        if (in_array($member->role, ['manager', 'operations'], true)) {
            $halls = DB::table('hall_assignments')->where('company_id', $this->companyId)
                ->where('membership_id', $member->id)->pluck('hall_id')->all();
            $q->whereIn('hall_id', $halls);
        }
        $options = $q->orderBy('event_date')->limit(50)->get(['id', 'event_date', 'program'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->event_date.' '.$row->program])->all();

        // A signed-in staff member may open a valid older proposal by exact
        // reference even when it falls outside the 50-item dropdown window.
        // Its scope must be independently rechecked, never inferred from URL.
        if ($this->bookingId !== '' && Str::isUuid($this->bookingId)
            && ! array_key_exists($this->bookingId, $options)) {
            $selected = DB::table('booking_drafts')->where('company_id', $this->companyId)
                ->where('id', $this->bookingId)
                ->whereIn('status', ['draft', 'under_review'])
                ->first(['id', 'hall_id', 'event_date', 'program']);
            if ($selected && app(OperationsPlanningPolicy::class)->plan(
                $actor, $this->companyId, $selected->hall_id
            )) {
                $options[$selected->id] = $selected->event_date.' '.$selected->program;
            }
        }

        return $options;
    }

    public function updatedStatusFilter(): void
    {
        $this->authorizeLoaded();
        $this->validate(['statusFilter' => ['required', 'in:all,planned,reviewed']]);
        $this->refresh();
    }

    public function showAllTasks(): void
    {
        $this->authorizeLoaded();
        $this->bookingId = '';
        $this->refresh();
    }

    public function refresh(): void
    {
        // Prevent stale history from surviving tenant/filter/task transitions.
        $this->historyTaskId = null;
        $this->historyEvents = [];
        $actor = $this->authorizeCompany();
        $this->validate([
            'statusFilter' => ['required', Rule::in(['all', 'planned', 'reviewed'])],
            'bookingId' => ['nullable', 'uuid'],
        ]);
        $this->tasks = app(OperationsPlanningService::class)->tasks(
            $actor, $this->companyId, 30, $this->statusFilter,
            $this->bookingId !== '' ? $this->bookingId : null
        );
        $this->loadedCompanyId = $this->companyId;
    }

    public function showHistory(string $taskId): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless(Str::isUuid($taskId)
            && collect($this->tasks)->contains('id', $taskId), 404);
        $this->historyEvents = app(OperationsPlanningService::class)
            ->history($actor, $this->companyId, $taskId);
        $this->historyTaskId = $taskId;
    }

    public function createTask(): void
    {
        $actor = $this->authorizeLoaded();
        $this->validate([
            'bookingId' => ['required', 'uuid'], 'taskTitle' => ['required', 'string', 'min:3', 'max:120'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        abort_unless(array_key_exists($this->bookingId, $this->proposalOptions()), 403);
        try {
            app(OperationsPlanningService::class)->create($actor, $this->companyId,
                $this->createRequestKey, $this->bookingId, $this->taskTitle, $this->reason);
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['reason' => $error->getMessage()]);
        }
        $this->createRequestKey = (string) Str::uuid();
        $this->taskTitle = '';
        $this->reason = '';
        $this->refresh();
    }

    public function markReviewed(string $taskId, bool $reviewed): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless(Str::isUuid($taskId), 404);
        $task = collect($this->tasks)->firstWhere('id', $taskId);
        abort_unless($task !== null, 404);
        $this->validate(['reviewReason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            app(OperationsPlanningService::class)->setReviewed(
                $actor, $this->companyId, $taskId,
                (int) $task['revision'], $reviewed, $this->reviewReason
            );
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['reviewReason' => $error->getMessage()]);
        }
        $this->reviewReason = '';
        $this->refresh();
    }
}
