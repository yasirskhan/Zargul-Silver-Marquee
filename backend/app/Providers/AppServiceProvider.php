<?php

namespace App\Providers;

use App\Services\TenantAccess;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantAccess::class);
    }

    public function boot(): void
    {
        //
    }
}
