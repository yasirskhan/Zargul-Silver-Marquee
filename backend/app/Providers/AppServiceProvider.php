<?php

namespace App\Providers;

use App\Services\TenantAccess;
use App\Policies\FinanceAccessPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantAccess::class);
    }

    public function boot(): void
    {
        // A fresh login is a fresh owner attention session. Clear only the
        // non-security UI dismissal marker, never authentication/MFA state.
        Event::listen(Login::class, static function (Login $login): void {
            if (request()->hasSession()) {
                request()->session()->forget(
                    'venuedesk.owner_approval_notice_dismissed.'.$login->user->getAuthIdentifier()
                );
            }
        });
        Gate::define('finance.view', [FinanceAccessPolicy::class, 'view']);
        Gate::define('finance.post', [FinanceAccessPolicy::class, 'post']);
    }
}
