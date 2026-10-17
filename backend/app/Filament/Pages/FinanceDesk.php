<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\FinanceControlService;
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

final class FinanceDesk extends Page
{
    protected string $view = 'filament.pages.finance-desk';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Finance review';

    protected static ?string $slug = 'finance-review';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public array $previews = [];

    #[Locked]
    public array $journals = [];

    #[Locked]
    public array $closeouts = [];

    #[Locked]
    public ?array $lastCloseResult = null;

    #[Locked]
    public string $previewRequestKey = '';

    #[Locked]
    public string $openingRequestKey = '';

    #[Locked]
    public string $reversalRequestKey = '';

    #[Locked]
    public string $closeoutRequestKey = '';

    private function initializeKeys(): void
    {
        $this->previewRequestKey = (string) Str::uuid();
        $this->openingRequestKey = (string) Str::uuid();
        $this->reversalRequestKey = (string) Str::uuid();
        $this->closeoutRequestKey = (string) Str::uuid();
    }

    public string $draftBookingId = '';

    public string $previewReason = '';

    public string $currency = '';

    public string $openingMinor = '';

    public string $journalReason = '';

    public string $closeDate = '';

    public string $countedMinor = '';

    public string $closeReason = '';

    public string $reversalReason = '';

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            && self::companiesFor($actor) !== [];
    }

    private static function companiesFor(User $actor): array
    {
        return CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->orderBy('company_id')->get()
            ->mapWithKeys(fn ($row) => [$row->company_id => $row->company->name])->all();
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            ? self::companiesFor($actor) : [];
    }

    public function role(): string
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User || $this->companyId === null) {
            return '';
        }
        return (string) CompanyMembership::query()->where('company_id', $this->companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)->value('role');
    }

    public function postingEnabled(): bool
    {
        return config('finance_controls.posting_enabled') === true;
    }

    public function proposalOptions(): array
    {
        if (! $this->companyId || ! array_key_exists($this->companyId, $this->companyOptions())) {
            return [];
        }
        return DB::table('booking_drafts')->where('company_id', $this->companyId)
            ->where('status', 'under_review')->orderBy('event_date')->limit(50)
            ->get(['id', 'event_date', 'program', 'revision'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->event_date.' '.$row->program
                .' revision '.$row->revision])->all();
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
        $this->closeDate = now('UTC')->toDateString();
        $this->initializeKeys();
        $this->refresh();
    }

    public function hydrate(): void
    {
        $this->authorizeCompany();
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

    public function updatedCompanyId(): void
    {
        // Never carry private preview instructions, cash values or audit
        // justifications from a different authorized operating company.
        $this->draftBookingId = '';
        $this->previewReason = '';
        $this->currency = '';
        $this->openingMinor = '';
        $this->journalReason = '';
        $this->closeDate = now('UTC')->toDateString();
        $this->countedMinor = '';
        $this->closeReason = '';
        $this->reversalReason = '';
        $this->lastCloseResult = null;
        $this->initializeKeys();
        $this->refresh();
    }

    public function refresh(): void
    {
        $actor = $this->authorizeCompany();
        $this->previews = app(FinanceControlService::class)->previews($actor, $this->companyId);
        $this->journals = app(FinanceControlService::class)->journals($actor, $this->companyId);
        $this->closeouts = app(FinanceControlService::class)->closeouts($actor, $this->companyId);
        $this->loadedCompanyId = $this->companyId;
    }

    public function createPreview(): void
    {
        $actor = $this->authorizeLoaded();
        $this->validate([
            'draftBookingId' => ['required', 'uuid'],
            'previewReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        abort_unless(array_key_exists($this->draftBookingId, $this->proposalOptions()), 403);
        $booking = DB::table('booking_drafts')->where('company_id', $this->companyId)
            ->where('id', $this->draftBookingId)->first();
        abort_unless($booking !== null, 404);
        try {
            app(FinanceControlService::class)->preview(
                $actor, $this->companyId, $this->previewRequestKey, $this->draftBookingId,
                (int) $booking->revision, $this->previewReason
            );
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['previewReason' => $error->getMessage()]);
        }
        $this->previewReason = '';
        $this->previewRequestKey = (string) Str::uuid();
        $this->refresh();
    }

    public function openCash(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->role() === 'owner', 403);
        $this->validate([
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'openingMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'journalReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        try {
            app(FinanceControlService::class)->openCash(
                $actor, $this->companyId, $this->openingRequestKey, $this->currency,
                (int) $this->openingMinor, $this->journalReason
            );
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['journalReason' => $error->getMessage()]);
        }
        $this->openingMinor = '';
        $this->journalReason = '';
        $this->openingRequestKey = (string) Str::uuid();
        $this->refresh();
    }

    public function reverseOpening(string $journalId): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->role() === 'owner' && Str::isUuid($journalId), 403);
        $this->validate(['reversalReason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            app(FinanceControlService::class)->reverseOpening(
                $actor, $this->companyId, $this->reversalRequestKey,
                $journalId, $this->reversalReason
            );
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['reversalReason' => $error->getMessage()]);
        }
        $this->reversalReason = '';
        $this->reversalRequestKey = (string) Str::uuid();
        $this->refresh();
    }

    public function closeCash(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->role() === 'owner', 403);
        $this->validate([
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'closeDate' => ['required', 'date_format:Y-m-d'],
            'countedMinor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'closeReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        try {
            $this->lastCloseResult = app(FinanceControlService::class)->closeCash(
                $actor, $this->companyId, $this->closeoutRequestKey, $this->currency,
                $this->closeDate, (int) $this->countedMinor, $this->closeReason
            );
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['closeReason' => $error->getMessage()]);
        }
        $this->closeReason = '';
        $this->closeoutRequestKey = (string) Str::uuid();
        $this->refresh();
    }
}
