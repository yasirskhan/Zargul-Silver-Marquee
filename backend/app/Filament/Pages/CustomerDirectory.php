<?php

namespace App\Filament\Pages;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CustomerProfileService;
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
    public int $page = 1;
    public int $perPage = 15;
    #[Locked]
    public ?string $selectedCustomerId = null;
    #[Locked]
    public int $selectedRevision = 0;
    public string $editName = '';
    public string $editEmail = '';
    public string $editPhone = '';
    public string $editReason = '';

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

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedCompanyId(): void
    {
        abort_unless(array_key_exists((string) $this->companyId, $this->companyOptions()), 403);
        $this->page = 1;
        $this->selectedCustomerId = null;
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(): array
    {
        if ($this->loadedCompanyId === null) return [];
        $needle = mb_strtolower(trim($this->search));
        return DB::table('customer_profiles')->where('company_id', $this->companyId)
            ->where('is_active', true)->orderByDesc('created_at')->get()
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

    public function pageRows(): array
    {
        return array_slice($this->rows(), max(0, ($this->page - 1) * $this->perPage), $this->perPage);
    }

    public function totalRows(): int
    {
        return count($this->rows());
    }

    public function openCustomer(string $customerId): void
    {
        abort_unless($this->loadedCompanyId !== null, 403);
        $row = DB::table('customer_profiles')->where('company_id', $this->companyId)
            ->where('id', $customerId)->where('is_active', true)->first();
        abort_unless($row !== null, 404);
        $this->selectedCustomerId = $customerId;
        $this->selectedRevision = (int) $row->revision;
        $this->editName = Crypt::decryptString($row->name_ciphertext);
        $this->editEmail = $row->email_ciphertext ? Crypt::decryptString($row->email_ciphertext) : '';
        $this->editPhone = $row->phone_ciphertext ? Crypt::decryptString($row->phone_ciphertext) : '';
        $this->editReason = '';
    }

    public function selectedCustomer(): ?array
    {
        if ($this->selectedCustomerId === null) return null;
        foreach ($this->rows() as $row) {
            if ($row['id'] === $this->selectedCustomerId) {
                $row['reservations'] = DB::table('venue_reservations')->where('company_id', $this->companyId)
                    ->where('customer_id', $row['id'])->orderByDesc('event_date')->get()
                    ->map(fn ($r) => ['id' => $r->id, 'date' => $r->event_date, 'program' => $r->program,
                        'guests' => (int) $r->guests, 'status' => $r->status])->all();
                return $row;
            }
        }
        return null;
    }

    public function closeCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->selectedRevision = 0;
        $this->reset(['editName', 'editEmail', 'editPhone', 'editReason']);
    }

    public function saveCustomer(): void
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User && $this->selectedCustomerId !== null, 403);
        $this->validate([
            'editName' => ['required', 'string', 'min:2', 'max:120'],
            'editEmail' => ['nullable', 'email', 'max:254'],
            'editPhone' => ['nullable', 'string', 'min:7', 'max:32'],
            'editReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        abort_unless($this->selectedRevision >= 1, 409);
        try {
            $this->selectedRevision = app(CustomerProfileService::class)->update($actor, $this->companyId, $this->selectedCustomerId,
                $this->editName, $this->editEmail !== '' ? $this->editEmail : null,
                $this->editPhone !== '' ? $this->editPhone : null,
                $this->selectedRevision, $this->editReason);
        } catch (\Throwable $e) {
            $this->addError('editReason', $e->getMessage());
            return;
        }
        $this->editReason = '';
        $this->openCustomer($this->selectedCustomerId);
        session()->flash('customer_saved', 'Customer profile updated.');
    }
}
