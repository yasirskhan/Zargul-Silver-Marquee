<?php
namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SecuritySettingsService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class EnforceTwoFactorSetup
{
    public function handle(Request $request,Closure $next):mixed
    {
        $user=Auth::user();
        if(!$user instanceof User)return $next($request);
        if(str_contains($request->path(),'multi-factor')||$request->is('*/logout')||$request->is('*/profile'))return $next($request);
        $service=app(SecuritySettingsService::class);
        $company=$service->activeCompanyFor($user,$request);
        if($company===null)return $next($request);
        $request->session()->put('active_company_id',$company);
        if(!$service->requiresMfa($user,$company)){
            $request->session()->put('zargul_mfa_company_id',$company);
            return $next($request);
        }
        if(!filled($user->getAppAuthenticationSecret())){
            return redirect()->guest(Filament::getProfileUrl());
        }
        if($request->session()->get('zargul_mfa_company_id')===$company)return $next($request);
        if($service->isTrusted($user,$company,$request)){
            $request->session()->put('zargul_mfa_company_id',$company);
            return $next($request);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/admin/login?company='.$company);
    }
}
