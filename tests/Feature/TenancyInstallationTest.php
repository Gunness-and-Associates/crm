<?php

use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

// Z-8.1 (BACKEND_BRIEF_ZAIN.md §14 step 1-2): package installed, central
// tables in place, app-level Tenant model, all four bootstrappers registered.

uses(DatabaseTruncation::class);

it('registers the app Tenant model, not the package default', function () {
    expect(config('tenancy.tenant_model'))->toBe(Tenant::class)
        ->and(new Tenant)->toBeInstanceOf(TenantWithDatabase::class);
});

it('registers the database, cache, filesystem and queue bootstrappers', function () {
    expect(config('tenancy.bootstrappers'))->toBe([
        DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ]);
});

it('creates the central tenants, domains and platform_users tables', function () {
    expect(Schema::hasTable('tenants'))->toBeTrue()
        ->and(Schema::hasTable('domains'))->toBeTrue()
        ->and(Schema::hasTable('platform_users'))->toBeTrue();
});

it('gives platform users a char(36) uuid primary key, distinct from CRM users', function () {
    $platformUser = PlatformUser::factory()->create();

    expect($platformUser->getKeyName())->toBe('id')
        ->and($platformUser->getIncrementing())->toBeFalse()
        ->and($platformUser->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

it('hashes the platform user password and hides it from serialization', function () {
    $platformUser = PlatformUser::factory()->create(['password' => 'plaintext-password']);

    expect($platformUser->password)->not->toBe('plaintext-password')
        ->and($platformUser->toArray())->not->toHaveKey('password');
});

// Z-8.4 (BACKEND_BRIEF_ZAIN.md §14 step 7): "add tenants:migrate to the
// deployment pipeline" -- the command itself ships with the package
// (registered by its own service provider); the only app-specific wiring is
// migration_parameters pointing it at database/migrations/tenant, not the
// default migrations/ folder.
it('points tenants:migrate at database/migrations/tenant', function () {
    $parameters = config('tenancy.migration_parameters');

    expect(is_array($parameters) ? $parameters['--path'] ?? null : null)->toBe([database_path('migrations/tenant')]);
});

it('runs tenants:migrate against an existing tenant without error', function () {
    $tenant = promotePrimaryTenant();

    $this->artisan('tenants:migrate', ['--tenants' => [$tenant->id]])->assertExitCode(0);
});
