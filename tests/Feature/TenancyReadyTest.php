<?php

use Illuminate\Support\Facades\File;

// CI guard for the tenancy-ready rules (PROJECT_PLAN §3, rules 1, 2, 4).
// A violation fails the build, which is the whole point of deferring multi-tenancy.

it('keeps CRM migrations out of the default migrations folder', function () {
    // Only Laravel's central infrastructure (cache, jobs, and — from Phase 8 —
    // tenants/domains/platform_users) may live in the default folder. Everything
    // CRM — users, roles/permissions, settings, entities, metadata — lives in
    // database/migrations/tenant/, so it becomes per-tenant.
    $offenders = collect(File::files(database_path('migrations')))
        ->map(fn ($file) => $file->getFilename())
        ->reject(fn (string $name) => str_contains($name, 'create_cache_table')
            || str_contains($name, 'create_jobs_table')
            || str_contains($name, 'create_tenants_table')
            || str_contains($name, 'create_domains_table')
            || str_contains($name, 'create_platform_users_table'))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('never adds a tenant_id column outside a tenant migration', function () {
    // The central `domains` table legitimately has a tenant_id FK (stancl/tenancy) —
    // that's central infrastructure, not row-level CRM tenant scoping, which this
    // rule exists to forbid. Row-level tenant_id columns are still banned everywhere.
    foreach (File::allFiles(database_path('migrations/tenant')) as $file) {
        expect(File::get($file->getPathname()))
            ->not->toContain("'tenant_id'")
            ->not->toContain('"tenant_id"');
    }
});

it('keeps routes/central.php an empty stub until Phase 8', function () {
    expect(File::get(base_path('routes/central.php')))->not->toContain('Route::');
});
