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

    #[Locked]
    public ?string $verificationMessage = null;

    public string $requestedTermMonths = '12';

    #[Locked]
    public ?string $renewalReviewMessage = null;

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
        $this->verificationMessage=null;
        $this->renewalReviewMessage=null;
        $this->refreshStatus();
    }

    /**
     * Explicit owner renewal CONTACT request through encrypted local support.
     * No money, subscription or signed entitlement can be issued here.
     */
    public function requestRenewalReview():void
    {
        $this->renewalReviewMessage=null;
        $actor=Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId!==null &&
            static::canAccess() &&
            array_key_exists($this->companyId,$this->companyOptions()),403);
        abort_unless(in_array($this->requestedTermMonths,['6','12'],true),422);
        try {
            app(\App\Services\CentralOwnerLicenseReviewRequest::class)->queue(
                $actor,$this->companyId,(int)$this->requestedTermMonths);
            $this->renewalReviewMessage='License review request recorded in the private support queue. No payment or renewal has been approved.';
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            abort(403);
        } catch (\InvalidArgumentException) {
            $this->renewalReviewMessage='Unable to request review for this license. Contact HAFZATECH support.';
        }
        $this->refreshStatus();
    }

    /**
     * Explicit, owner-scoped action. Never makes an online license request
     * during mount, hydration, periodic UI polling or local refresh.
     */
    public function verifyLicense(): void
    {
        $this->verificationMessage=null;
        $actor=Filament::auth()->user();
        abort_unless($actor instanceof User && $this->companyId !== null &&
            static::canAccess() &&
            array_key_exists($this->companyId,$this->companyOptions()),403);
        try {
            $result=app(\App\Services\CentralOwnerManualLicenseVerification::class)
                ->verify($actor,$this->companyId,now()->toDateTimeImmutable());
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            abort(403);
        }
        $this->verificationMessage=match($result['state']) {
            'verified'=>'License signature and HAFZATECH online status verified.',
            'rate_limited'=>'A verification was just requested. Please try again shortly.',
            'pending_signed_license'=>'The central service has not delivered a valid signed license.',
            'license_attention'=>'HAFZATECH reports an issue with this license. Contact support.',
            default=>'Unable to verify online now. Your locally signed license remains unchanged.',
        };
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
