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
        $company=$service->companyFor($user);
        if($company===null)return true;
        if(!$service->requiresMfa($user,$company))return false;
        return !$service->isTrusted($user);
    }

    public function verifyCode(#[\SensitiveParameter] string $code,#[\SensitiveParameter] ?string $secret=null,bool $shouldPreventCodeReuse=false):bool
    {
        $ok=parent::verifyCode($code,$secret,$shouldPreventCodeReuse);
        if($ok){
            $user=Filament::auth()->user();
            if($user instanceof User)app(SecuritySettingsService::class)->trustCurrentDevice($user);
        }
        return $ok;
    }
}
