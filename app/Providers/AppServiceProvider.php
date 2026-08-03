<?php

namespace App\Providers;

use App\Support\Settings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tenancy-ready rule 1: all CRM migrations live in database/migrations/tenant/
        // (this becomes the per-tenant migration path at Phase 8). The default
        // database/migrations/ folder holds only central infrastructure (cache, jobs).
        $this->loadMigrationsFrom(database_path('migrations/tenant'));
    }
}
