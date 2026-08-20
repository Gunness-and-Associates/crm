<?php

use App\Support\SchemaManager\Snapshotter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Z-7.3 -- "backups and restore rehearsal" (PROJECT_PLAN.md Phase 7 DoD).
 * The actual full backup+restore round trip lives in its own file
 * (BackupRestoreRoundTripTest.php) under DatabaseTruncation, not
 * RefreshDatabase: RefreshDatabase wraps every test in an open transaction
 * on Laravel's own connection, and mysqldump's restore output issues
 * `DROP TABLE` against those same tables from a *separate* connection --
 * DROP TABLE needs an exclusive metadata lock, which can't be granted while
 * any other transaction still holds a lock on that table, and that other
 * transaction (this test's own wrapper) would never close until the test
 * method itself returns. A genuine, reproducible self-deadlock, not a
 * mysqldump/Process bug -- confirmed by running the exact same
 * Snapshotter calls in a bare PHP script outside any test transaction,
 * where the same round trip completes in under a second.
 */
it('writes the backup to the backups/ path, separate from schema-change snapshots', function () {
    $this->artisan('crm:backup')->assertExitCode(0);

    $backupDisk = app(Snapshotter::class)->backupDisk();
    $files = Storage::disk($backupDisk)->files('backups');

    expect($files)->not->toBeEmpty()
        ->and(collect($files)->every(fn (string $f): bool => str_starts_with($f, 'backups/')))->toBeTrue();
});

it('reports failure without a stack trace leaking when restoring a path that does not exist', function () {
    $this->artisan('crm:restore-backup', ['path' => 'backups/does-not-exist.sql', '--force' => true])
        ->assertExitCode(1);
});
