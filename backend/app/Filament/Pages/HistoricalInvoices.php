<?php

namespace App\Filament\Pages;

use App\Models\Hall;
use App\Models\User;
use App\Services\TenantAccess;
use App\Services\VenueInvoicePaymentService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Historical, read-only owner/admin invoice statements. An expired license
 * never deletes customer financial records. Unlike FinanceDesk, this page
 * has no cash-posting, refund, invoice issuance or approval actions.
 */
final class HistoricalInvoices extends Page
{
    protected string $view = 'filament.pages.historical-invoices';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static ?string $navigationLabel = 'Past invoices';
    protected static ?string $slug = 'historical-invoices';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    public string $selectedInvoiceId = '';

    #[Locked]
    public array $invoices = [];

    #[Locked]
    public ?array $statement = null;

    #[Locked]
    public ?string $loadedCompanyId = null;

    private static function visibleCompanies(User $actor): array
    {
        if (! DB::table('users')->where('id', $actor->getKey())
            ->where('is_active', true)->whereNotNull('email_verified_at')->exists()) {
            return [];
        }
        $query=DB::table('company_memberships as m')
            ->join('companies as c', 'c.id', '=', 'm.company_id')
            ->where('m.user_id', $actor->getKey())
            ->where('m.is_active', true)->where('c.is_active', true)
            ->whereIn('m.role', ['owner', 'admin']);

        if (config('central_control.activation_enabled') === true) {
            $query->join('central_installation_activation as a', 'a.company_id', '=', 'c.id')
                ->where('a.singleton_id', 1)
                ->where('a.status', 'registered_pending_license');
        }
        return $query->orderBy('c.name')->pluck('c.name', 'c.id')->all();
    }

    public static function canAccess(): bool
    {
        $actor=Filament::auth()->user();
        return $actor instanceof User && self::visibleCompanies($actor) !== [];
    }

    public function companyOptions(): array
    {
        $actor=Filament::auth()->user();
        return $actor instanceof User ? self::visibleCompanies($actor) : [];
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $options=$this->companyOptions();
        $requested=$this->companyId ?? request()->query('company');
        abort_unless($requested === null ||
            (is_string($requested) && array_key_exists($requested, $options)), 403);
        $this->companyId=$requested ?? array_key_first($options);
        $this->refreshInvoices();
    }

    public function hydrate(): void
    {
        $this->authorizeCompany();
    }

    private function authorizeCompany(): User
    {
        $actor=Filament::auth()->user();
        abort_unless($actor instanceof User && self::canAccess()
            && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        return $actor;
    }

    public function updatedCompanyId(): void
    {
        $this->selectedInvoiceId='';
        $this->refreshInvoices();
    }

    public function refreshInvoices(): void
    {
        $actor=$this->authorizeCompany();
        $this->statement=null;
        $this->invoices=[];
        $this->loadedCompanyId=null;
        // Invoice IDs are never selected from a cross-company query. A
        // second per-hall entitlement check protects historical data even
        // during commercial post-grace or account role changes.
        $rows=DB::table('venue_invoices as i')
            ->join('venue_reservations as r', fn ($join) => $join
                ->on('r.id', '=', 'i.reservation_id')
                ->on('r.company_id', '=', 'i.company_id'))
            ->join('halls as h', fn ($join) => $join
                ->on('h.id', '=', 'r.hall_id')
                ->on('h.company_id', '=', 'i.company_id'))
            ->where('i.company_id', $this->companyId)
            ->where('h.is_active', true)
            ->orderByDesc('i.number')
            ->limit(100)
            ->get(['i.id', 'i.invoice_number', 'i.currency_code',
                'i.total_minor', 'i.issued_at', 'h.id as hall_id']);
        $access=app(TenantAccess::class);
        foreach ($rows as $row) {
            $hall=Hall::query()->where('id', $row->hall_id)
                ->where('company_id', $this->companyId)
                ->where('is_active', true)->first();
            if ($hall === null || ! $access->canReadExistingInvoiceHistory($actor, $hall)) {
                continue;
            }
            $this->invoices[]=[
                'id'=>$row->id, 'number'=>$row->invoice_number,
                'currency'=>$row->currency_code,
                'totalMinor'=>(int)$row->total_minor,
                'issuedAt'=>(string)$row->issued_at,
            ];
        }
        $this->loadedCompanyId=$this->companyId;
    }

    public function viewStatement(): void
    {
        $actor=$this->authorizeCompany();
        abort_unless($this->loadedCompanyId===$this->companyId &&
            Str::isUuid($this->selectedInvoiceId) &&
            collect($this->invoices)->contains(
                fn ($r) => $r['id']===$this->selectedInvoiceId),403);
        try {
            $this->statement=app(VenueInvoicePaymentService::class)
                ->history($actor, $this->companyId, $this->selectedInvoiceId);
        } catch (AuthorizationException) {
            abort(403);
        }
    }
}
