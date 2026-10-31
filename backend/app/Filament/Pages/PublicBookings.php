<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\TenantAccess;
use BackedEnum;
use Filament\Facades\Filament;
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
        })->values()->all();
    }
}