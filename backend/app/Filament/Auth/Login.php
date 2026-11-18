<?php
namespace App\Filament\Auth;

use App\Services\SecuritySettingsService;
use Filament\Auth\Http\Responses\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;

final class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $response=parent::authenticate();
        $user=Filament::auth()->user();
        if($response!==null&&$user instanceof \App\Models\User){
            $company=app(SecuritySettingsService::class)->activeCompanyFor($user,request());
            if($company!==null)session(['zargul_mfa_company_id'=>$company]);
        }
        return $response;
    }
}
