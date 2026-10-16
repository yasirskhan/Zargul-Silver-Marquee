<?php

namespace App\Providers;

use App\Services\TenantAccess;
use App\Policies\FinanceAccessPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantAccess::class);
    }

    public function boot(): void
    {
        Gate::define('finance.view', [FinanceAccessPolicy::class, 'view']);
        Gate::define('finance.post', [FinanceAccessPolicy::class, 'post']);
    }
}
