<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\ApprovalWorkflowService;
use App\Services\TenantAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Role-aware entry point using only aggregate internal metadata.
 * It never returns contact details, financial amounts or draft contents.
 */
final class VenueOverview extends Page
{
    protected string $view = 'filament.pages.venue-overview';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $slug = 'overview';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    #[Locked]
    public ?string $loadedCompanyId = null;

    #[Locked]
    public array $summary = [];

    private const STAFF_ROLES = ['owner', 'admin', 'manager', 'operations', 'reception', 'cashier'];

    private static function accountAllowed(User $actor): bool
    {
        return DB::table('users')->where('id', $actor->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists();
    }

    private static function companiesFor(User $actor): array
    {
        return CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', self::STAFF_ROLES)
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->orderBy('company_id')->get()
            ->mapWithKeys(fn ($member) => [$member->company_id => $member->company->name])
            ->all();
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && self::accountAllowed($actor)
            && self::companiesFor($actor) !== [];
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();

        return $actor instanceof User && self::accountAllowed($actor)
            ? self::companiesFor($actor) : [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $options = $this->companyOptions();
        $requested = $this->companyId ?? request()->query('company');
        abort_unless($requested === null || (is_string($requested)
            && array_key_exists($requested, $options)), 403);
        $this->companyId = $requested ?? array_key_first($options);
        $this->refreshSummary();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        // The private summary is a snapshot, not an authorization grant.
        // Refresh it after every Livewire round trip in case a former owner
        // has just been downgraded, or their company/hall role changed.
        $this->refreshSummary();
    }

    public function updatedCompanyId(): void
    {
        $this->refreshSummary();
    }

    public function refreshSummary(): void
    {
        $this->summary = [];
        $this->loadedCompanyId = null;
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && static::canAccess()
            && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);

        $member = CompanyMembership::query()->where('company_id', $this->companyId)
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', self::STAFF_ROLES)->first();
        abort_unless($member !== null, 403);

        $access = app(TenantAccess::class);
        $halls = Hall::query()->where('company_id', $this->companyId)
            ->where('is_active', true)->get()
            ->filter(fn (Hall $hall) => $access->canViewHall($actor, $hall))->count();
        $role = $member->role;
        $canSeeCompanyProposals = in_array($role, ['owner', 'admin'], true);
        $canReadApprovals = in_array($role, ['owner', 'admin', 'manager'], true);
        $canSearch = in_array($role, ['owner', 'admin', 'manager', 'operations'], true);

        $this->summary = [
            'role' => $role,
            'visibleHalls' => $halls,
            'drafts' => $canSeeCompanyProposals
                ? DB::table('booking_drafts')->where('company_id', $this->companyId)
                    ->whereIn('status', ['draft', 'under_review'])->count() : null,
            'pendingApprovals' => $canReadApprovals
                ? app(ApprovalWorkflowService::class)->inboxCounts($actor, $this->companyId)['pending'] : null,
            'canSeeCompanyProposals' => $canSeeCompanyProposals,
            'canReadApprovals' => $canReadApprovals,
            'canReviewFinance' => $canSeeCompanyProposals,
            'canSearch' => $canSearch,
            'canPlan' => $halls > 0 && in_array($role, ['owner', 'admin', 'manager', 'operations'], true),
            'canManageHallRules' => $halls > 0 && in_array($role, ['owner', 'admin'], true),
            'canEditWebsite' => (bool) $access->canEditWebsite($actor, $this->companyId),
            'canReadAudit' => (bool) $access->canReadAuditHistory($actor, $this->companyId),
        ];
        $this->loadedCompanyId = $this->companyId;
    }
}
