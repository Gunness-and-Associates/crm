<?php

use App\Support\SchemaManager\Snapshotter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Storage;

/**
 * Z-8.4 (BACKEND_BRIEF_ZAIN.md §14 step 8: "per-tenant backup and export").
 * Uses promotePrimaryTenant() (tests/Pest.php) rather than provisioning a
 * genuinely separate database here: Snapshotter::connectionConfig() just
 * reads whatever the current default connection is, so a tenant pointed at
 * this same physical test database exercises the exact same code path a
 * tenant with its own real database would, without the extra time/cleanup
 * cost of creating and dropping one (that path is already covered by
 * CreateTenantCommandTest).
 */
uses(DatabaseTruncation::class);

it('backs up a specific tenant under a path naming that tenant', function () {
    $tenant = promotePrimaryTenant();

    $this->artisan('crm:backup-tenant', ['--tenants' => [$tenant->id]])->assertExitCode(0);

    $backupDisk = app(Snapshotter::class)->backupDisk();
    $files = Storage::disk($backupDisk)->files("backups/tenant-{$tenant->id}");

    expect($files)->not->toBeEmpty();
});

it('backs up every tenant when none is named', function () {
    $tenant = promotePrimaryTenant();

    $this->artisan('crm:backup-tenant')->assertExitCode(0);

    $backupDisk = app(Snapshotter::class)->backupDisk();
    $files = Storage::disk($backupDisk)->files("backups/tenant-{$tenant->id}");

    expect($files)->not->toBeEmpty();
});
