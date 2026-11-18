<?php
namespace App\Filament\Auth;

use App\Models\User;
use App\Services\SecuritySettingsService;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;

final class TrustedAppAuthentication extends AppAuthentication
{
    public function isEnabled(Authenticatable $user): bool
    {
        if(!parent::isEnabled($user))return false;
        if(!$user instanceof User)return true;
        $service=app(SecuritySettingsService::class);
        $company=$service->activeCompanyFor($user,request());
        if($company===null)return true;
        if(!$service->requiresMfa($user,$company))return false;
        return !$service->isTrusted($user,$company);
    }

    public function verifyCode(#[\SensitiveParameter] string $code,#[\SensitiveParameter] ?string $secret=null,bool $shouldPreventCodeReuse=false):bool
    {
        $ok=parent::verifyCode($code,$secret,$shouldPreventCodeReuse);
        if($ok){
            session(['zargul_mfa_verified_at'=>now()->timestamp]);
            $user=Filament::auth()->user();
            if($user instanceof User){
                $company=app(SecuritySettingsService::class)->activeCompanyFor($user,request());
                if($company!==null)session(['zargul_mfa_company_id'=>$company]);
            }
        }
        return $ok;
    }
}
