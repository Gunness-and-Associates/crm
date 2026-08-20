<?php

use App\Models\Company;
use App\Support\SchemaManager\Snapshotter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTruncation::class);

/**
 * Z-7.3 -- "backups and restore rehearsal" (PROJECT_PLAN.md Phase 7 DoD):
 * proves the actual round trip works against a real database, not just
 * that the mysqldump/mysql commands ran without a nonzero exit code.
 *
 * Deliberately DatabaseTruncation, not RefreshDatabase: RefreshDatabase
 * wraps every test in an open transaction on Laravel's own connection, and
 * the restore's `DROP TABLE ... CREATE TABLE ...` (mysqldump's own output)
 * runs via a *separate* connection (a real `mysql` subprocess) -- that
 * DROP TABLE needs an exclusive metadata lock, which can never be granted
 * while the wrapping transaction still holds a lock on the row this test
 * itself just inserted, and that transaction would only close once the test
 * method returns. A genuine self-deadlock, reproduced and confirmed
 * separately by running the same Snapshotter calls in a bare script outside
 * any test transaction, where the identical round trip completes in under a
 * second. DatabaseTruncation migrates once and truncates between tests
 * instead, with no transaction left open during the test body.
 */
it('backs up and restores the whole database, recovering data that was deleted in between', function () {
    $company = Company::factory()->create(['industry' => 'Rehearsal Industry']);

    $this->artisan('crm:backup')->assertExitCode(0);

    $backupDisk = app(Snapshotter::class)->backupDisk();
    $path = collect(Storage::disk($backupDisk)->files('backups'))->sort()->last();
    expect($path)->not->toBeNull();

    $company->forceDelete();
    expect(Company::withoutGlobalScopes()->find($company->id))->toBeNull();

    $this->artisan('crm:restore-backup', ['path' => $path, '--force' => true])->assertExitCode(0);

    $restored = Company::withoutGlobalScopes()->find($company->id);
    expect($restored)->not->toBeNull()
        ->and($restored->industry)->toBe('Rehearsal Industry');
});
