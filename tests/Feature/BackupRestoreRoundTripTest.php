<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;

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
    // Other tests in the same run (BackupRehearsalTest, and now Z-8.4's
    // per-tenant backup tests) also write into this same disk, and different
    // parallel *workers* share the same physical storage disk (unlike the
    // database, which is per-worker) -- so picking "the file that just
    // appeared" by listing the directory is inherently racy against whatever
    // another worker writes at the same moment, no matter how the listing is
    // filtered or timed. Confirmed: an earlier version of this test picked
    // "the latest file in backups/ by name" and reproducibly failed under a
    // full parallel run (passed every time run in isolation) -- a different
    // worker's file landed in between this test's own write and its own
    // listing. Reading the path crm:backup itself reports it wrote, instead
    // of re-discovering it via the directory, has no dependency on what else
    // is in that directory at all.
    $company = Company::factory()->create(['industry' => 'Rehearsal Industry']);

    // Artisan::call(), not $this->artisan(): the test helper runs the command
    // against its own mocked output (for expectsOutput()/assertExitCode()),
    // which Artisan::output() below can't see. Artisan::call() goes through
    // the real console kernel, whose output buffer Artisan::output() reads.
    $exitCode = Artisan::call('crm:backup');
    expect($exitCode)->toBe(0);

    preg_match('/Backup written: (\S+)/', Artisan::output(), $matches);
    $path = $matches[1] ?? null;
    expect($path)->not->toBeNull();

    $company->forceDelete();
    expect(Company::withoutGlobalScopes()->find($company->id))->toBeNull();

    $this->artisan('crm:restore-backup', ['path' => $path, '--force' => true])->assertExitCode(0);

    $restored = Company::withoutGlobalScopes()->find($company->id);
    expect($restored)->not->toBeNull()
        ->and($restored->industry)->toBe('Rehearsal Industry');
});
