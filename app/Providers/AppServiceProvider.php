<?php

namespace App\Providers;

use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Support\MetadataRepository;
use App\Support\Settings;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(MetadataRepository::class);
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

        // Password policy: min 12 with mixed case, numbers and symbols; checked against
        // known breaches in production. Used everywhere via Password::defaults().
        Password::defaults(function () {
            $rule = Password::min(12)->mixedCase()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Bump the compiled-metadata cache version on any registry change, so a Studio
        // edit is live immediately with no deploy (BACKEND_BRIEF §5).
        $bump = fn (): int => $this->app->make(MetadataRepository::class)->bump();
        foreach ([Module::class, Field::class, OptionList::class, OptionItem::class, Layout::class] as $model) {
            $model::saved($bump);
            $model::deleted($bump);
        }
    }
}
