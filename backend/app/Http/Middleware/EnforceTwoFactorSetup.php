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
        $company=$service->companyFor($user);
        if($company===null||!$service->requiresMfa($user,$company))return $next($request);
        if(filled($user->getAppAuthenticationSecret()))return $next($request);
        return redirect()->guest(Filament::getProfileUrl());
    }
}
