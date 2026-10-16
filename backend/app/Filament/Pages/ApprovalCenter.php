<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ApprovalCenter extends Page
{
    protected string $view = 'filament.pages.approval-center';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $navigationLabel = 'Approval center';

    protected static ?string $slug = 'approval-center';

    public ?string $companyId = null;

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public array $entries = [];

    #[Locked]
    public ?string $selectedId = null;

    #[Locked]
    public int $selectedRevision = 0;

    public string $newCategory = 'discount';

    public string $newAmount = '';

    public string $newCurrency = '';

    public string $newBookingId = '';

    public string $newReason = '';

    public string $decisionReason = '';

    public string $grantAdminId = '';

    public array $grantCategories = [];

    public string $grantCeiling = '';

    public string $grantStarts = '';

    public string $grantEnds = '';

    public string $grantReason = '';

    public string $revokeReason = '';

    #[Locked]
    public ?string $telegramPairingUrl = null;

    public function telegramConfigured(): bool
    {
        return config('approval_alerts.enabled') === true;
    }

    public function telegramConnected(): bool
    {
        $actor = Filament::auth()->user();
        return $this->role() === 'owner' && $actor instanceof User
            && app(\App\Services\TelegramApprovalAlertService::class)
                ->hasActiveLink($actor, $this->companyId);
    }

    public function beginTelegramPairing(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->role() === 'owner', 403);
        try {
            $start = app(\App\Services\TelegramApprovalAlertService::class)
                ->beginPairing($actor, $this->companyId);
            $this->telegramPairingUrl = $start['url'];
        } catch (ConflictHttpException $error) {
            throw ValidationException::withMessages(['telegramPairingUrl' => $error->getMessage()]);
        }
    }

    public function revokeTelegramPairing(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->role() === 'owner', 403);
        try {
            app(\App\Services\TelegramApprovalAlertService::class)->revoke(
                $actor, $this->companyId
            );
        } catch (ConflictHttpException $error) {
            throw ValidationException::withMessages(['telegramPairingUrl' => $error->getMessage()]);
        }
        $this->telegramPairingUrl = null;
    }

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
            ->whereIn('role', ['owner', 'admin', 'manager'])
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->orderBy('company_id')->get()
            ->mapWithKeys(fn ($m) => [$m->company_id => $m->company->name])->all();
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
        if (! $actor instanceof User || ! $this->companyId) {
            return '';
        }

        return (string) CompanyMembership::query()->where('company_id', $this->companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)->value('role');
    }

    public function adminOptions(): array
    {
        if ($this->role() !== 'owner') {
            return [];
        }

        return CompanyMembership::query()->with('user')
            ->where('company_id', $this->companyId)->where('role', 'admin')
            ->where('is_active', true)
            ->whereHas('user', fn ($q) => $q->where('is_active', true)
                ->whereNotNull('email_verified_at'))->orderBy('user_id')->get()
            ->mapWithKeys(fn ($m) => [$m->user_id => $m->user->name])->all();
    }

    public function proposalOptions(): array
    {
        if (! $this->companyId || ! in_array($this->role(), ['owner', 'admin', 'manager'], true)) {
            return [];
        }
        $query = DB::table('booking_drafts')->where('booking_drafts.company_id', $this->companyId)
            ->where('booking_drafts.status', 'under_review');
        if ($this->role() === 'manager') {
            $actor = Filament::auth()->user();
            $query->join('hall_assignments', function ($join) use ($actor): void {
                $join->on('hall_assignments.company_id', '=', 'booking_drafts.company_id')
                    ->on('hall_assignments.hall_id', '=', 'booking_drafts.hall_id')
                    ->whereIn('hall_assignments.membership_id', CompanyMembership::query()
                        ->where('company_id', $this->companyId)->where('user_id', $actor->getKey())
                        ->where('is_active', true)->pluck('id')->all());
            });
        }
        return $query->orderBy('booking_drafts.event_date')->limit(50)
            ->get(['booking_drafts.id', 'booking_drafts.event_date', 'booking_drafts.program'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->event_date.' '.$row->program.' ('.$row->id.')'])
            ->all();
    }

    public function delegations(): array
    {
        $actor = $this->authorizeLoaded();
        if ($this->role() !== 'owner') {
            return [];
        }
        return app(ApprovalWorkflowService::class)->activeDelegations(
            $actor, $this->companyId
        );
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->companyId = array_key_first($this->companyOptions());
        $this->refreshInbox();
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
        $this->selectedId = null;
        $this->selectedRevision = 0;
        $this->telegramPairingUrl = null;
        $this->refreshInbox();
    }

    public function refreshInbox(): void
    {
        $actor = $this->authorizeCompany();
        $this->entries = app(ApprovalWorkflowService::class)->inbox($actor, $this->companyId);
        $this->loadedCompanyId = $this->companyId;
        $this->selectedId = null;
        $this->selectedRevision = 0;
        $this->decisionReason = '';
    }

    public function selectRequest(string $id): void
    {
        $this->authorizeLoaded();
        $entry = collect($this->entries)->firstWhere('id', $id);
        abort_unless(Str::isUuid($id) && $entry !== null, 404);
        // Caller cannot choose a stale/foreign revision merely by mutating state.
        $this->selectedId = $id;
        $this->selectedRevision = $entry['revision'];
        $this->decisionReason = '';
    }

    public function submitRequest(): void
    {
        $actor = $this->authorizeLoaded();
        $this->validate([
            'newCategory' => ['required', 'in:discount,refund,overtime_waiver,complimentary_service,price_override,expense'],
            'newAmount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'newCurrency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'newReason' => ['required', 'string', 'min:3', 'max:500'],
            'newBookingId' => ['nullable', 'uuid'],
        ]);
        try {
            app(ApprovalWorkflowService::class)->request(
                $actor, $this->companyId, (string) Str::uuid(), $this->newCategory,
                (int) $this->newAmount, $this->newCurrency,
                $this->newBookingId === '' ? null : $this->newBookingId, $this->newReason,
            );
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['newReason' => $error->getMessage()]);
        }
        $this->newReason = '';
        $this->newAmount = '';
        $this->refreshInbox();
    }

    public function approve(): void
    {
        $this->decide('approved');
    }

    public function reject(): void
    {
        $this->decide('rejected');
    }

    private function decide(string $decision): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->selectedId !== null, 404);
        $this->validate(['decisionReason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            app(ApprovalWorkflowService::class)->decide(
                $actor, $this->companyId, $this->selectedId,
                $this->selectedRevision, $decision, $this->decisionReason,
            );
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['decisionReason' => $error->getMessage()]);
        }
        $this->refreshInbox();
    }

    public function grant(): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->role() === 'owner', 403);
        $this->validate([
            'grantAdminId' => ['required', 'integer'],
            'grantCategories' => ['required', 'array', 'min:1'],
            'grantCeiling' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'grantStarts' => ['required', 'date_format:Y-m-d\\TH:i'],
            'grantEnds' => ['required', 'date_format:Y-m-d\\TH:i'],
            'grantReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        abort_unless(array_key_exists($this->grantAdminId, $this->adminOptions()), 403);
        try {
            app(ApprovalWorkflowService::class)->grant(
                $actor, $this->companyId, (string) Str::uuid(), (int) $this->grantAdminId,
                $this->grantCategories, (int) $this->grantCeiling,
                $this->grantStarts.'+00:00', $this->grantEnds.'+00:00', $this->grantReason,
            );
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['grantReason' => $error->getMessage()]);
        }
        $this->grantReason = '';
        $this->refreshInbox();
    }

    public function revoke(string $id): void
    {
        $actor = $this->authorizeLoaded();
        abort_unless($this->role() === 'owner' && Str::isUuid($id), 403);
        $this->validate(['revokeReason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            app(ApprovalWorkflowService::class)->revoke($actor, $this->companyId, $id, $this->revokeReason);
        } catch (InvalidArgumentException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['revokeReason' => $error->getMessage()]);
        }
        $this->revokeReason = '';
        $this->refreshInbox();
    }
}
