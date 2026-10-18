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
        $visibleHallIds = Hall::query()->where('company_id', $this->companyId)
            ->where('is_active', true)->get()
            ->filter(fn (Hall $hall) => $access->canViewHall($actor, $hall))
            ->pluck('id')->all();
        $halls = count($visibleHallIds);
        $role = $member->role;
        // Signed-license restriction applies to normal venue operations, not
        // to independently scoped HAFZATECH support and license renewal.
        $canOperate = app(\App\Services\CentralVenueOperationsAccessGate::class)
            ->allowsCompany($this->companyId);
        $canReadSupport = in_array($role, ['owner', 'admin'], true);
        $canSeeCompanyProposals = $canOperate && $canReadSupport;
        $canReadApprovals = $canOperate &&
            in_array($role, ['owner', 'admin', 'manager'], true);
        $canSearch = $canOperate &&
            in_array($role, ['owner', 'admin', 'manager', 'operations'], true);
        $canPlan = $halls > 0 && in_array($role, ['owner', 'admin', 'manager', 'operations'], true);
        $weekFrom = now()->toDateString();
        $weekThrough = now()->addDays(6)->toDateString();

        $this->summary = [
            'role' => $role,
            'visibleHalls' => $halls,
            'hallsNeedingSetup' => $canSeeCompanyProposals
                ? DB::table('halls')->where('company_id', $this->companyId)
                    ->whereIn('id', $visibleHallIds)
                    ->where('is_active', true)
                    ->where('program_rules_revision', '<', 1)->count() : null,
            'reviewDrafts' => $canSeeCompanyProposals
                ? DB::table('booking_drafts')->where('company_id', $this->companyId)
                    ->where('status', 'under_review')->count() : null,
            'drafts' => $canSeeCompanyProposals
                ? DB::table('booking_drafts')->where('company_id', $this->companyId)
                    ->whereIn('status', ['draft', 'under_review'])->count() : null,
            'upcomingDrafts' => $canSeeCompanyProposals
                ? DB::table('booking_drafts')->where('company_id', $this->companyId)
                    ->whereIn('status', ['draft', 'under_review'])
                    ->whereBetween('event_date', [$weekFrom, $weekThrough])->count() : null,
            'upcomingFrom' => $canSeeCompanyProposals ? $weekFrom : null,
            'upcomingThrough' => $canSeeCompanyProposals ? $weekThrough : null,
            'pendingApprovals' => $canReadApprovals
                ? app(ApprovalWorkflowService::class)->inboxCounts($actor, $this->companyId)['pending'] : null,
            'recentProposals' => $canSeeCompanyProposals
                ? DB::table('booking_drafts as b')
                    ->join('halls as h', fn ($join) => $join
                        ->on('h.id', '=', 'b.hall_id')->on('h.company_id', '=', 'b.company_id'))
                    ->where('b.company_id', $this->companyId)
                    ->whereIn('b.hall_id', $visibleHallIds)
                    ->whereIn('b.status', ['draft', 'under_review'])
                    ->orderByDesc('b.created_at')->orderByDesc('b.id')->limit(3)
                    ->get(['b.id', 'b.status', 'b.event_date', 'b.program', 'h.name as hall_name'])
                    ->map(fn ($row) => [
                        'id' => $row->id, 'status' => $row->status,
                        'eventDate' => $row->event_date, 'program' => $row->program,
                        'hallName' => $row->hall_name,
                    ])->all() : [],
            'hafzatechSupportQueued' => $canReadSupport &&
                DB::table('central_installation_activation')
                    ->where('company_id',$this->companyId)
                    ->where('status','registered_pending_license')->exists()
                ? DB::table('central_support_outbox as queued')
                    ->join('central_installation_activation as enrollment',
                        fn($join) => $join->on('enrollment.installation_id','=','queued.installation_id')
                            ->on('enrollment.company_id','=','queued.company_id'))
                    ->where('queued.company_id',$this->companyId)
                    ->where('enrollment.status','registered_pending_license')
                    ->where('queued.delivery_state','queued')->count() : null,
            'hafzatechSupportUnread' => $canReadSupport &&
                DB::table('central_installation_activation')
                    ->where('company_id',$this->companyId)
                    ->where('status','registered_pending_license')->exists()
                ? app(\App\Services\CentralSupportReplyInboxReader::class)
                    ->unreadCount($actor,$this->companyId) : null,
            'canSeeCompanyProposals' => $canSeeCompanyProposals,
            'canReadApprovals' => $canReadApprovals,
            'canReviewFinance' => $canSeeCompanyProposals,
            'recentPreviewReferences' => $canSeeCompanyProposals
                ? DB::table('finance_quote_previews')
                    ->where('company_id', $this->companyId)
                    ->orderByDesc('created_at')->orderByDesc('id')->limit(3)
                    ->get(['id', 'program', 'booking_id'])
                    ->map(fn ($row) => [
                        'id' => $row->id, 'program' => $row->program,
                        'bookingId' => $row->booking_id,
                    ])->all() : [],
            'canSearch' => $canSearch,
            'canPlan' => $canPlan,
            'plannedTasks' => $canPlan
                ? DB::table('operations_plan_tasks')->where('company_id', $this->companyId)
                    ->where('status', 'planned')
                    ->whereIn('hall_id', $visibleHallIds)->count() : null,
            'recentPlans' => $canPlan
                ? DB::table('operations_plan_tasks as t')
                    ->join('booking_drafts as b', fn ($join) => $join
                        ->on('b.id', '=', 't.booking_id')->on('b.company_id', '=', 't.company_id'))
                    ->join('halls as h', fn ($join) => $join
                        ->on('h.id', '=', 't.hall_id')->on('h.company_id', '=', 't.company_id'))
                    ->where('t.company_id', $this->companyId)
                    ->whereIn('t.hall_id', $visibleHallIds)
                    ->whereIn('b.status', ['draft', 'under_review'])
                    ->orderByDesc('t.created_at')->orderByDesc('t.id')->limit(3)
                    ->get(['t.id', 't.booking_id', 't.title', 't.status',
                        'h.name as hall_name', 'b.event_date', 'b.program'])
                    ->map(fn ($row) => [
                        'id' => $row->id, 'bookingId' => $row->booking_id,
                        'title' => $row->title, 'status' => $row->status,
                        'hallName' => $row->hall_name,
                        'eventDate' => $row->event_date, 'program' => $row->program,
                    ])->all() : [],
            'canManageHallRules' => $halls > 0 && in_array($role, ['owner', 'admin'], true),
            'canEditWebsite' => (bool) $access->canEditWebsite($actor, $this->companyId),
            'canReadAudit' => (bool) $access->canReadAuditHistory($actor, $this->companyId),
        ];
        $this->loadedCompanyId = $this->companyId;
    }
}
