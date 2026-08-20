<?php

namespace App\Providers;

use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Models\Role;
use App\Support\Acl;
use App\Support\ActivityBlueprintMacro;
use App\Support\Api\ApiModuleRegistry;
use App\Support\Api\ApiScopes;
use App\Support\Api\ApiTrace;
use App\Support\ContactableBlueprintMacro;
use App\Support\Etl\LegacyViewDefReader;
use App\Support\Livewire\SubdirectoryHandleRequests;
use App\Support\MetadataRepository;
use App\Support\RuntimeMailConfigurator;
use App\Support\SchemaManager\SchemaManager;
use App\Support\SchemaManager\Snapshotter;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(MetadataRepository::class);
        $this->app->singleton(Snapshotter::class);
        $this->app->singleton(SchemaManager::class);
        $this->app->singleton(Acl::class);
        $this->app->scoped(ApiTrace::class);

        // Local-only path to the legacy SuiteCRM install's public/legacy
        // directory (config/etl.php) -- never present in CI or production.
        $this->app->singleton(LegacyViewDefReader::class, function (): LegacyViewDefReader {
            $root = config('etl.legacy_php_root');

            return new LegacyViewDefReader(is_string($root) && $root !== '' ? $root : null);
        });

        // The app may be served from a sub-directory (e.g. XAMPP Apache at
        // /newcrmga/public) rather than a vhost root — see SubdirectoryHandleRequests.
        $this->app->singleton(HandleRequests::class, SubdirectoryHandleRequests::class);

        // Z-5.3: PassportServiceProvider reads these two during ITS OWN boot() to
        // decide which routes to register — register() runs for every provider before
        // boot() runs for any of them, so this must happen here, not in boot(), or it
        // takes effect one bootstrap cycle too late.
        Passport::$deviceCodeGrantEnabled = false; // authorization-code/device flows are out of scope.
        Passport::$registersJsonApiRoutes = true; // /oauth/clients + /oauth/personal-access-tokens for free.
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

        ContactableBlueprintMacro::register();
        ActivityBlueprintMacro::register();

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

        // Dynamic ACL registration (BACKEND_BRIEF §8.4): a new module starts 'none' for
        // every role, and a new role starts 'none' for every module — no silent gaps,
        // new capability is never granted implicitly.
        Module::created(fn (Module $module) => $this->app->make(Acl::class)->registerModule($module->key));
        Role::created(fn (Role $role) => $this->app->make(Acl::class)->registerRole($role->id));

        // Z-5.3: OAuth2 client-credentials + personal access tokens, per
        // docs/contracts/api-contract.md §1.1. Scopes are built from the same module
        // registry ModuleResourceController uses (Z-5.2) — a module registered there
        // needs no separate scope-list update here.
        // Z-8.3 -- FilesystemTenancyBootstrapper suffixes storage_path() per tenant
        // once tenancy initializes; Passport's default key lookup is storage_path()-
        // based, which would otherwise 404 the real oauth-*.key files the moment any
        // request goes through tenant resolution. boot() runs before any HTTP request
        // (and so before tenancy ever initializes), so this captures the real,
        // un-suffixed path once and pins Passport to it regardless of tenant context.
        Passport::loadKeysFrom(storage_path());

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));
        Passport::tokensCan((new ApiScopes($this->app->make(ApiModuleRegistry::class)))->all());

        // docs/contracts/api-contract.md §1.6 — "600 requests per minute per client,
        // configurable per client". AuthenticateApiToken resolves the client once and
        // stores it on the request, so no extra query here.
        RateLimiter::for('api', function (Request $request) {
            $client = $request->attributes->get('oauth_client');
            $perMinute = $client instanceof Client && $client->rate_limit_per_minute !== null
                ? $client->rate_limit_per_minute
                : 600;

            return Limit::perMinute($perMinute)->by($client instanceof Client ? $client->id : $request->ip());
        });

        // Build the SMTP mailer from the settings store, never .env (Z-4.1). No database
        // may be reachable yet at this boot (composer's package:discover, a fresh install
        // before the first migrate) — fall back to config/mail.php as shipped rather than
        // fail the entire boot over a mailer nicety.
        try {
            if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'is_secret')) {
                $this->app->make(RuntimeMailConfigurator::class)->apply();
            }
        } catch (\Throwable) {
            // Intentionally swallowed — see comment above.
        }
    }
}
