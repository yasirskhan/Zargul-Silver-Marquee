<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\TenantAccess;
use BackedEnum;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class BookingProposals extends Page
{
    protected string $view = 'filament.pages.booking-proposals';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Booking proposals';

    protected static ?string $slug = 'booking-proposals';

    private const PAGE_SIZE = 25;

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
        $this->companyId = array_key_first($this->companyOptions());
        $this->refreshResults();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
    }

    public function updatedCompanyId(): void
    {
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
