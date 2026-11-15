<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\PublicBookingFinanceBridge;
use App\Services\TenantAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

final class PublicBookings extends Page
{
    protected string $view = 'filament.pages.public-bookings';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;
    protected static ?string $navigationLabel = 'Public bookings';
    protected static ?string $slug = 'public-bookings';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && $actor->is_active && $actor->hasVerifiedEmail()
            && CompanyMembership::query()->where('user_id', $actor->id)
                ->whereIn('role', ['owner', 'admin', 'manager'])->where('is_active', true)
                ->whereHas('company', fn ($q) => $q->where('is_active', true))->exists();
    }

    public function mount(): void
    {
        $this->companyId = array_key_first($this->companyOptions());
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User) return [];
        return CompanyMembership::query()->where('user_id', $actor->id)
            ->whereIn('role', ['owner', 'admin', 'manager'])->where('is_active', true)
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->with('company')->get()->mapWithKeys(fn ($m) => [$m->company_id => $m->company->name])->all();
    }

    public function connectFinance(string $confirmationId): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId, 403);
        $result = app(PublicBookingFinanceBridge::class)
            ->connect($actor, $this->companyId, $confirmationId);
        Notification::make()->title($result['created']
            ? 'Reservation, invoice and deposit receipt created'
            : 'Booking finance records already exist')->success()->send();
    }
    public function bookings(): array
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User || ! $this->companyId
            || ! array_key_exists($this->companyId, $this->companyOptions())) {
            return [];
        }
        $rows = DB::table('public_booking_confirmations')->where('company_id', $this->companyId)
            ->orderByDesc('confirmed_at')->limit(100)->get();
        $access = app(TenantAccess::class);
        return $rows->filter(function ($row) use ($actor, $access): bool {
            $hall = \App\Models\Hall::query()->where('company_id', $row->company_id)
                ->where('id', $row->hall_id)->first();
            return $hall !== null && $access->canViewHall($actor, $hall);
        })->map(function ($row): object {
            $invoiceId=PublicBookingFinanceBridge::invoiceIdFor($row->id);
            $invoice=DB::table('venue_invoices')->where('company_id',$row->company_id)
                ->where('id',$invoiceId)->first(['id','invoice_number','total_minor','currency_code']);
            if($invoice){
                $paid=(int)DB::table('venue_invoice_payments')->where('company_id',$row->company_id)
                    ->where('invoice_id',$invoiceId)->sum('amount_minor');
                $refunded=(int)DB::table('venue_invoice_refunds')->where('company_id',$row->company_id)
                    ->where('invoice_id',$invoiceId)->sum('amount_minor');
                $invoice->paid_minor=$paid-$refunded;
                $invoice->due_minor=(int)$invoice->total_minor-($paid-$refunded);
            }
            $row->finance=$invoice;
            return $row;
        })->values()->all();
    }
}