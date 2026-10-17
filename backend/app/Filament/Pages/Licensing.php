<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\CentralLicensingOverviewService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Private, read-only owner licensing status. The installation may not be
 * publicly exposed until an active verified owner binds its company through
 * the approved installer; there is no manual activation or issuer route here.
 */
final class Licensing extends Page
{
    protected string $view = 'filament.pages.licensing';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Licensing';

    protected static ?string $slug = 'licensing';

    #[Url(as: 'company')]
    public ?string $companyId = null;

    #[Locked]
    public array $summary = [];

    /** @return array<string,string> */
    private static function allowedCompanies(User $actor): array
    {
        if (! DB::table('users')->where('id',$actor->getKey())
            ->where('is_active',true)->whereNotNull('email_verified_at')->exists()) {
            return [];
        }
        return DB::table('company_memberships as m')
            ->join('companies as c','c.id','=','m.company_id')
            ->join('central_installation_activation as a','a.company_id','=','c.id')
            ->where('m.user_id',$actor->getKey())
            ->where('m.is_active',true)->where('c.is_active',true)
            ->where('a.status','registered_pending_license')
            ->whereIn('m.role',['owner','admin'])->orderBy('c.name')
            ->pluck('c.name','c.id')->all();
    }

    public static function canAccess(): bool
    {
        $actor=Filament::auth()->user();
        return $actor instanceof User && static::allowedCompanies($actor)!==[];
    }

    public function companyOptions(): array
    {
        $actor=Filament::auth()->user();
        return $actor instanceof User ? static::allowedCompanies($actor) : [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(),403);
        $options=$this->companyOptions();
        $requested=$this->companyId ?? request()->query('company');
        abort_unless($requested===null || (is_string($requested) &&
            array_key_exists($requested,$options)),403);
        $this->companyId=$requested ?? array_key_first($options);
        $this->refreshStatus();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->companyId!==null &&
            array_key_exists($this->companyId,$this->companyOptions()),403);
        // Verify persisted role, account and company binding on every request.
        $this->refreshStatus();
    }

    public function updatedCompanyId(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->summary=[];
        $actor=Filament::auth()->user();
        abort_unless($actor instanceof User && static::canAccess() &&
            $this->companyId!==null &&
            array_key_exists($this->companyId,$this->companyOptions()),403);
        $this->summary=app(CentralLicensingOverviewService::class)
            ->snapshotForCompany($actor,$this->companyId,now()->toDateTimeImmutable());
    }
}
