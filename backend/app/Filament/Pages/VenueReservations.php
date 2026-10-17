<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\TenantAccess;
use App\Services\VenueReservationService;
use App\Services\VenueQuoteService;
use App\Services\VenueQuoteIssueService;
use App\Services\VenueInvoiceService;
use App\Services\VenueInvoicePaymentService;
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
    public array $customerReservations = [];

    #[Locked]
    public array $quoteSnapshots = [];

    #[Locked]
    public array $quoteIssues = [];

    #[Locked]
    public array $issuedInvoices = [];

    #[Locked]
    public array $paymentBalances = [];

    #[Locked]
    public string $paymentRequestKey = '';

    public string $paymentAmountMinor = '';
    public string $paymentMethod = 'cash';
    public string $paymentReference = '';
    public string $paymentReason = '';

    #[Locked]
    public string $invoiceRequestKey = '';

    public string $invoiceIssueReason = '';

    #[Locked]
    public string $quoteIssueRequestKey = '';

    public string $quoteIssuePurpose = '';

    #[Locked]
    public string $quoteRequestKey = '';

    public string $quoteExtraChairs = '0';
    public string $quoteExtraTables = '0';
    public array $quoteAddonQuantities = [];
    public string $quoteReason = '';

    #[Locked]
    public string $selectedCustomerId = '';

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
    public string $newHallId = '';

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

    public function mayViewCustomerHistory(): bool
    {
        $actor = $this->authorizeCompany();
        return app(TenantAccess::class)->canManageCustomerProfiles($actor, $this->companyId);
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
        $this->quoteRequestKey = (string) Str::uuid();
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
        $this->selectedCustomerId = '';
        $this->customerReservations = [];
        $this->resetQuoteForm();
        $this->reason = '';
        $this->newDate = '';
        $this->newProgram = 'evening';
        $this->newHallId = '';
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

    public function showCustomerHistory(string $customerId): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->mayViewCustomerHistory() && Str::isUuid($customerId), 403);
        $this->customerReservations = app(VenueReservationService::class)
            ->customerHistory($actor, $this->companyId, $customerId);
        $this->selectedCustomerId = $customerId;
    }

    public function clearCustomerHistory(): void
    {
        $this->authorizeLoaded();
        $this->selectedCustomerId = '';
        $this->customerReservations = [];
    }

    private function resetQuoteForm(): void
    {
        $this->quoteSnapshots = [];
        $this->quoteIssues = [];
        $this->issuedInvoices = [];
        $this->paymentBalances = [];
        $this->paymentAmountMinor = '';
        $this->paymentMethod = 'cash';
        $this->paymentReference = '';
        $this->paymentReason = '';
        $this->paymentRequestKey = (string) Str::uuid();
        $this->invoiceIssueReason = '';
        $this->invoiceRequestKey = (string) Str::uuid();
        $this->quoteIssuePurpose = '';
        $this->quoteIssueRequestKey = (string) Str::uuid();
        $this->quoteExtraChairs = '0';
        $this->quoteExtraTables = '0';
        $this->quoteAddonQuantities = [];
        $this->quoteReason = '';
        $this->quoteRequestKey = (string) Str::uuid();
    }

    /** Only the current owner/admin may price a confirmed private reservation. */
    public function quoteAddons(): array
    {
        $actor = $this->authorizeLoaded();
        if (! $this->mayViewCustomerHistory() || $this->selectedReservationId === null) {
            return [];
        }
        $selected = collect($this->reservations)->firstWhere('id', $this->selectedReservationId);
        abort_unless(is_array($selected), 403);
        $hall = Hall::query()->where('company_id', $this->companyId)
            ->where('id', $selected['hallId'])->where('is_active', true)->firstOrFail();
        abort_unless(app(TenantAccess::class)->canManageBookings($actor, $hall), 403);
        if ((int) $hall->addon_catalog_revision < 1) {
            return [];
        }
        $catalog = DB::table('hall_addon_catalog_revisions')->where('company_id', $this->companyId)
            ->where('hall_id', $hall->id)
            ->where('revision_number', $hall->addon_catalog_revision)->first();
        abort_unless($catalog !== null, 409);
        return array_values(array_filter(json_decode($catalog->items, true, 512, JSON_THROW_ON_ERROR),
            fn (array $item): bool => $item['enabled'] === true));
    }

    public function paymentPostingEnabled(): bool
    {
        return config('payment_controls.enabled') === true
            && config('finance_controls.posting_enabled') === true;
    }

    private function refreshPaymentBalances(User $actor): void
    {
        $this->paymentBalances = [];
        foreach ($this->issuedInvoices as $invoice) {
            $this->paymentBalances[$invoice['id']] =
                app(VenueInvoicePaymentService::class)->history(
                    $actor, $this->companyId, $invoice['id']);
        }
    }

    /** Explicit authorized receipt/deposit; never simulate external settlement. */
    public function recordInvoicePayment(string $invoiceId): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->paymentPostingEnabled() && $this->mayViewCustomerHistory()
            && $this->selectedReservationId !== null && Str::isUuid($invoiceId), 403);
        abort_unless(collect($this->issuedInvoices)->contains('id', $invoiceId), 403);
        $this->validate([
            'paymentAmountMinor' => ['required','integer','min:1','max:100000000000'],
            'paymentMethod' => ['required','in:cash,bank'],
            'paymentReference' => ['nullable','string','max:120'],
            'paymentReason' => ['required','string','min:3','max:500'],
        ]);
        try {
            app(VenueInvoicePaymentService::class)->record(
                $actor, $this->companyId, $invoiceId,
                $this->paymentRequestKey, $this->paymentMethod,
                (int) $this->paymentAmountMinor,
                $this->paymentMethod === 'cash' ? null : $this->paymentReference,
                $this->paymentReason);
        } catch (InvalidArgumentException | ConflictHttpException $e) {
            throw ValidationException::withMessages(['paymentReason' => $e->getMessage()]);
        }
        $this->paymentRequestKey = (string) Str::uuid();
        $this->paymentAmountMinor = '';
        $this->paymentReference = '';
        $this->paymentReason = '';
        $this->refreshPaymentBalances($actor);
    }

    public function invoiceIssuanceEnabled(): bool
    {
        return config('invoice_controls.issuance_enabled') === true
            && is_int(config('invoice_controls.tax_rate_bps'))
            && config('invoice_controls.tax_rate_bps') >= 0
            && config('invoice_controls.tax_rate_bps') <= 10000;
    }

    /** Create an audited numbered invoice ONLY from a currently listed immutable quote. */
    public function issueInvoice(string $numberedQuoteId): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->invoiceIssuanceEnabled() && $this->mayViewCustomerHistory()
            && $this->selectedReservationId !== null && Str::isUuid($numberedQuoteId), 403);
        abort_unless(collect($this->quoteIssues)->contains('id', $numberedQuoteId), 403);
        $this->validate(['invoiceIssueReason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            app(VenueInvoiceService::class)->issue($actor, $this->companyId,
                $numberedQuoteId, $this->invoiceRequestKey, $this->invoiceIssueReason);
        } catch (ConflictHttpException | InvalidArgumentException $e) {
            throw ValidationException::withMessages(['invoiceIssueReason' => $e->getMessage()]);
        }
        $this->invoiceIssueReason = '';
        $this->invoiceRequestKey = (string) Str::uuid();
        $this->issuedInvoices = app(VenueInvoiceService::class)->list(
            $actor, $this->companyId, $this->selectedReservationId);
        $this->refreshPaymentBalances($actor);
    }

    public function quoteIssuanceEnabled(): bool
    {
        return config('quote_controls.issuance_enabled') === true;
    }

    public function issueInternalQuote(string $snapshotId): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->quoteIssuanceEnabled() && $this->mayViewCustomerHistory()
            && $this->selectedReservationId !== null && Str::isUuid($snapshotId), 403);
        $this->validate(['quoteIssuePurpose' => ['required', 'string', 'min:3', 'max:500']]);
        abort_unless(collect($this->quoteSnapshots)->contains('id', $snapshotId), 403);
        try {
            app(VenueQuoteIssueService::class)->issue(
                $actor, $this->companyId, $snapshotId,
                $this->quoteIssueRequestKey, $this->quoteIssuePurpose,
            );
        } catch (ConflictHttpException | InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quoteIssuePurpose' => $e->getMessage()]);
        }
        $this->quoteIssuePurpose = '';
        $this->quoteIssueRequestKey = (string) Str::uuid();
        $this->quoteIssues = app(VenueQuoteIssueService::class)->list(
            $actor, $this->companyId, $this->selectedReservationId,
        );
    }

    public function freezeQuote(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->mayViewCustomerHistory() && $this->selectedReservationId !== null, 403);
        $this->validate([
            'quoteExtraChairs' => ['required', 'integer', 'min:0', 'max:100000'],
            'quoteExtraTables' => ['required', 'integer', 'min:0', 'max:100000'],
            'quoteReason' => ['required', 'string', 'min:3', 'max:500'],
            'quoteAddonQuantities' => ['array', 'max:100'],
            'quoteAddonQuantities.*' => ['integer', 'min:0', 'max:100000'],
        ]);
        $selected = collect($this->reservations)->firstWhere('id', $this->selectedReservationId);
        abort_unless(is_array($selected) && $selected['status'] === 'confirmed', 403);
        $allowed = array_column($this->quoteAddons(), 'code');
        $requested = [];
        foreach ($this->quoteAddonQuantities as $code => $quantity) {
            abort_unless(in_array($code, $allowed, true), 403);
            if ((int) $quantity > 0) {
                $requested[] = ['code' => $code, 'quantity' => (int) $quantity];
            }
        }
        try {
            app(VenueQuoteService::class)->create(
                $actor, $this->companyId, $this->selectedReservationId,
                $this->quoteRequestKey, $this->selectedRevision,
                (int) $this->quoteExtraChairs, (int) $this->quoteExtraTables,
                $requested, $this->quoteReason,
            );
        } catch (ConflictHttpException | InvalidArgumentException $e) {
            throw ValidationException::withMessages(['quoteReason' => $e->getMessage()]);
        }
        $this->quoteRequestKey = (string) Str::uuid();
        $this->quoteReason = '';
        $this->quoteSnapshots = app(VenueQuoteService::class)->list(
            $actor, $this->companyId, $this->selectedReservationId,
        );
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
        $this->newHallId = $selected['hallId'];
        $this->reason = '';
        $this->claimRequestKey = (string) Str::uuid();
        $this->resetQuoteForm();
        if ($this->mayViewCustomerHistory()) {
            $this->quoteSnapshots = app(VenueQuoteService::class)->list(
                $this->authorizeLoaded(), $this->companyId, $reservationId,
            );
            $this->quoteIssues = app(VenueQuoteIssueService::class)->list(
                $this->authorizeLoaded(), $this->companyId, $reservationId,
            );
            $this->issuedInvoices = app(VenueInvoiceService::class)->list(
                $this->authorizeLoaded(), $this->companyId, $reservationId,
            );
            $this->refreshPaymentBalances($this->authorizeLoaded());
        }
    }

    public function rescheduleSelected(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->enabled() && $this->selectedReservationId !== null, 403);
        $this->validate([
            'newDate' => ['required', 'date_format:Y-m-d'],
            'newProgram' => ['required', 'in:morning,noon,evening'],
            'newHallId' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        abort_unless(array_key_exists($this->newHallId, $this->hallOptions()), 403);
        try {
            app(VenueReservationService::class)->reschedule(
                $actor, $this->companyId, $this->selectedReservationId,
                $this->newDate, $this->newProgram,
                $this->claimRequestKey, $this->selectedRevision,
                $this->reason, $this->newHallId
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
        $this->resetQuoteForm();
        $this->refresh();
    }
}
