<?php

use Illuminate\Support\Facades\File;

// CI guard for the tenancy-ready rules (PROJECT_PLAN §3, rules 1, 2, 4).
// A violation fails the build, which is the whole point of deferring multi-tenancy.

it('keeps CRM migrations out of the default migrations folder', function () {
    // Only Laravel's central infrastructure (cache, jobs) may live in the default folder.
    // Everything CRM — users, roles/permissions, settings, entities, metadata — lives in
    // database/migrations/tenant/, so it becomes per-tenant at Phase 8.
    $offenders = collect(File::files(database_path('migrations')))
        ->map(fn ($file) => $file->getFilename())
        ->reject(fn (string $name) => str_contains($name, 'create_cache_table')
            || str_contains($name, 'create_jobs_table'))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('never adds a tenant_id column in any migration', function () {
    foreach (File::allFiles(database_path('migrations')) as $file) {
        expect(File::get($file->getPathname()))
            ->not->toContain("'tenant_id'")
            ->not->toContain('"tenant_id"');
    }
});

it('keeps routes/central.php an empty stub until Phase 8', function () {
    expect(File::get(base_path('routes/central.php')))->not->toContain('Route::');
});
