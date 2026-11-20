<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\TenantAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** Owner/admin customer directory. Contact data is decrypted only for authorised staff. */
final class CustomerDirectory extends Page
{
    protected string $view = 'filament.pages.customer-directory';
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;
    protected static ?string $navigationLabel = 'Customers';
    protected static ?string $slug = 'customers';

    #[Url(as: 'company')]
    public ?string $companyId = null;
    #[Locked]
    public ?string $loadedCompanyId = null;
    public string $search = '';

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        return $actor instanceof User && CompanyMembership::query()
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])->exists();
    }

    public function companyOptions(): array
    {
        $actor = Filament::auth()->user();
        if (! $actor instanceof User) return [];
        return CompanyMembership::query()->with('company')
            ->where('user_id', $actor->getKey())->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->get()->mapWithKeys(fn ($m) => [$m->company_id => $m->company->name])->all();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $options = $this->companyOptions();
        $requested = $this->companyId ?? request()->query('company');
        abort_unless($requested === null || (is_string($requested) && array_key_exists($requested, $options)), 403);
        $this->companyId = $requested ?? array_key_first($options);
        abort_unless(app(TenantAccess::class)->canManageCustomerProfiles(Filament::auth()->user(), $this->companyId), 403);
        $this->loadedCompanyId = $this->companyId;
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions()), 403);
        abort_unless(app(TenantAccess::class)->canManageCustomerProfiles(Filament::auth()->user(), $this->companyId), 403);
        $this->loadedCompanyId = $this->companyId;
    }

    public function rows(): array
    {
        if ($this->loadedCompanyId === null) return [];
        $needle = mb_strtolower(trim($this->search));
        return DB::table('customer_profiles')->where('company_id', $this->companyId)
            ->where('is_active', true)->orderByDesc('created_at')->limit(500)->get()
            ->map(function ($row) {
                $name = Crypt::decryptString($row->name_ciphertext);
                $email = $row->email_ciphertext ? Crypt::decryptString($row->email_ciphertext) : '';
                $phone = $row->phone_ciphertext ? Crypt::decryptString($row->phone_ciphertext) : '';
                $bookings = DB::table('venue_reservations')->where('company_id', $this->companyId)
                    ->where('customer_id', $row->id)->count();
                $last = DB::table('venue_reservations')->where('company_id', $this->companyId)
                    ->where('customer_id', $row->id)->max('event_date');
                return ['id' => $row->id, 'name' => $name, 'email' => $email, 'phone' => $phone,
                    'bookings' => $bookings, 'last' => $last, 'revision' => (int) $row->revision];
            })->filter(fn ($r) => $needle === '' || str_contains(mb_strtolower($r['name'].' '.$r['email'].' '.$r['phone']), $needle))
            ->values()->all();
    }
}
