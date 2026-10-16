<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\BookingDraftService;
use App\Services\BookingDraftReviewService;
use App\Services\TenantAccess;
use BackedEnum;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BookingProposals extends Page
{
    protected string $view = 'filament.pages.booking-proposals';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Booking proposals';

    protected static ?string $slug = 'booking-proposals';

    private const PAGE_SIZE = 25;

    #[Url(as: 'company')]
    public ?string $companyId = null;

    public ?string $hallId = null;

    public string $statusFilter = '';

    public string $fromDate = '';

    public string $toDate = '';

    #[Locked]
    public array $entries = [];

    #[Locked]
    public bool $hasMore = false;

    #[Locked]
    public int $offset = 0;

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public ?string $selectedBookingId = null;

    #[Locked]
    public ?string $selectedCompanyId = null;

    #[Locked]
    public int $selectedRevision = 0;

    #[Locked]
    public string $selectedStatus = '';

    public string $editHallId = '';

    public string $editDate = '';

    public string $editProgram = '';

    public string $editGuests = '';

    public string $editReason = '';

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
            ->where('user_id', $actor->getKey())->where('is_active', true)
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

    public function hallOptions(): array
    {
        $options = [];
        if ($this->companyId === null || ! array_key_exists($this->companyId, $this->companyOptions())) {
            return $options;
        }
        foreach (Hall::query()->where('company_id', $this->companyId)
            ->orderBy('name')->get(['id', 'name']) as $hall) {
            $options[$hall->id] = $hall->name;
        }

        return $options;
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
        $this->refreshResults();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
    }

    public function updatedCompanyId(): void
    {
        $this->clearSelection();
        $this->hallId = null;
        $this->refreshResults();
    }

    public function refreshResults(): void
    {
        $this->offset = 0;
        $this->loadResults();
    }

    public function nextPage(): void
    {
        $this->authorizeLoaded();
        if (! $this->hasMore || $this->offset > 5000 - self::PAGE_SIZE) {
            return;
        }
        $this->offset += self::PAGE_SIZE;
        $this->loadResults();
    }

    public function previousPage(): void
    {
        $this->authorizeLoaded();
        $this->offset = max(0, $this->offset - self::PAGE_SIZE);
        $this->loadResults();
    }

    public function clearSelection(): void
    {
        $this->selectedBookingId = null;
        $this->selectedCompanyId = null;
        $this->selectedRevision = 0;
        $this->selectedStatus = '';
        $this->editHallId = '';
        $this->editDate = '';
        $this->editProgram = '';
        $this->editGuests = '';
        $this->editReason = '';
    }

    /** Load only a same-company saved draft. No customer contact enters Livewire state. */
    public function selectDraft(string $bookingId): void
    {
        $this->authorizeLoaded();
        abort_unless(\Illuminate\Support\Str::isUuid($bookingId), 404);
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);
        $record = app(BookingDraftService::class)->current($actor, $this->companyId, $bookingId);
        abort_unless($record !== null, 404);
        $this->selectedCompanyId = $this->companyId;
        $this->selectedBookingId = $record['bookingId'];
        $this->selectedRevision = $record['revision'];
        $this->selectedStatus = $record['status'];
        $this->editHallId = $record['hallId'];
        $this->editDate = $record['date'];
        $this->editProgram = $record['program'];
        $this->editGuests = (string) $record['guests'];
        $this->editReason = '';
    }

    public function saveDraft(): void
    {
        [$actor, $saved] = $this->editorContext();
        abort_unless($saved['status'] === 'draft', 409);
        $this->validate([
            'editHallId' => ['required', 'uuid'],
            'editDate' => ['required', 'date_format:Y-m-d'],
            'editProgram' => ['required', 'in:morning,noon,evening'],
            'editGuests' => ['required', 'integer', 'min:1', 'max:200000'],
            'editReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        abort_unless(array_key_exists($this->editHallId, $this->hallOptions()), 403);
        try {
            app(BookingDraftService::class)->update(
                $actor, $this->companyId, $this->selectedBookingId,
                $this->editHallId, $saved['customerId'], $this->editDate,
                $this->editProgram, (int) $this->editGuests,
                $this->selectedRevision, $this->editReason,
            );
        } catch (ConflictHttpException $error) {
            throw ValidationException::withMessages([
                'editReason' => 'The saved proposal changed or is no longer editable. Reload it before retrying.',
            ]);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['editReason' => $error->getMessage()]);
        }
        $this->refreshResults();
        $this->selectDraft($saved['bookingId']);
    }

    public function submitReview(): void
    {
        $this->changeStatus('submit');
    }

    public function withdrawDraft(): void
    {
        $this->changeStatus('withdraw');
    }

    private function changeStatus(string $action): void
    {
        [$actor, $saved] = $this->editorContext();
        $this->requireSavedForm($saved);
        $this->validate(['editReason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            $service = app(BookingDraftReviewService::class);
            if ($action === 'submit') {
                $service->submit($actor, $this->companyId,
                    $this->selectedBookingId, $this->selectedRevision, $this->editReason);
            } else {
                $service->withdraw($actor, $this->companyId,
                    $this->selectedBookingId, $this->selectedRevision, $this->editReason);
            }
        } catch (ConflictHttpException $error) {
            throw ValidationException::withMessages([
                'editReason' => 'The saved proposal changed or cannot make this transition. Reload it before retrying.',
            ]);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['editReason' => $error->getMessage()]);
        }
        $this->refreshResults();
        $this->selectDraft($saved['bookingId']);
    }

    private function requireSavedForm(array $saved): void
    {
        if ($this->editHallId !== $saved['hallId']
            || $this->editDate !== $saved['date']
            || $this->editProgram !== $saved['program']
            || $this->editGuests !== (string) $saved['guests']) {
            throw ValidationException::withMessages([
                'editReason' => 'Save or discard unsaved changes before changing proposal status.',
            ]);
        }
    }

    private function editorContext(): array
    {
        $this->authorizeLoaded();
        abort_unless($this->selectedCompanyId === $this->companyId
            && $this->selectedBookingId !== null, 403);
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);
        $saved = app(BookingDraftService::class)->current(
            $actor, $this->companyId, $this->selectedBookingId,
        );
        abort_unless($saved !== null, 404);
        if ($saved['revision'] !== $this->selectedRevision) {
            throw ValidationException::withMessages([
                'editReason' => 'The proposal changed. Reload it before making another change.',
            ]);
        }

        return [$actor, $saved];
    }

    private function authorizeLoaded(): void
    {
        abort_unless($this->companyId !== null
            && $this->loadedCompanyId === $this->companyId
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
    }

    private function loadResults(): void
    {
        $this->entries = [];
        $this->hasMore = false;
        $this->loadedCompanyId = null;

        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && static::canAccess()
            && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions())
            && app(TenantAccess::class)->canManageCustomerProfiles($actor, $this->companyId), 403);

        if ($this->hallId !== null && $this->hallId !== ''
            && ! array_key_exists($this->hallId, $this->hallOptions())) {
            abort(403);
        }
        if (! in_array($this->statusFilter, ['', 'draft', 'under_review', 'withdrawn'], true)) {
            throw ValidationException::withMessages(['statusFilter' => 'Choose a valid proposal status.']);
        }
        $from = $this->parseDate($this->fromDate);
        $to = $this->parseDate($this->toDate);
        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages(['fromDate' => 'Start date cannot exceed end date.']);
        }

        // Read only safe proposal metadata. No customer contact or free-form audit notes.
        $query = DB::table('booking_drafts')
            ->join('halls', function ($join): void {
                $join->on('halls.id', '=', 'booking_drafts.hall_id')
                    ->on('halls.company_id', '=', 'booking_drafts.company_id');
            })
            ->where('booking_drafts.company_id', $this->companyId);
        if ($this->hallId !== null && $this->hallId !== '') {
            $query->where('booking_drafts.hall_id', $this->hallId);
        }
        if ($this->statusFilter !== '') {
            $query->where('booking_drafts.status', $this->statusFilter);
        }
        if ($from !== null) {
            $query->whereDate('booking_drafts.event_date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('booking_drafts.event_date', '<=', $to);
        }
        $rows = $query->orderBy('booking_drafts.event_date')
            ->orderBy('booking_drafts.id')
            ->offset($this->offset)->limit(self::PAGE_SIZE + 1)
            ->get([
                'booking_drafts.id', 'halls.name as hall_name',
                'booking_drafts.event_date', 'booking_drafts.program',
                'booking_drafts.requested_guests', 'booking_drafts.status',
                'booking_drafts.revision',
            ]);

        $this->hasMore = $rows->count() > self::PAGE_SIZE;
        $this->entries = $rows->take(self::PAGE_SIZE)->map(fn (object $row): array => [
            'id' => $row->id, 'hallName' => $row->hall_name,
            'eventDate' => $row->event_date, 'program' => $row->program,
            'guests' => (int) $row->requested_guests,
            'status' => $row->status, 'revision' => (int) $row->revision,
        ])->values()->all();
        $this->loadedCompanyId = $this->companyId;
    }

    private function parseDate(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            throw ValidationException::withMessages(['fromDate' => 'Enter a valid date in YYYY-MM-DD format.']);
        }

        return $raw;
    }
}
