<?php

namespace App\Console\Commands;

use App\Support\SchemaManager\SnapshotFailed;
use App\Support\SchemaManager\Snapshotter;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Z-7.3 — restores a full backup written by BackupCommand. Destructive
 * (overwrites every table in the current database with the dump's
 * contents), so it asks for confirmation outside a non-interactive
 * context, same as Laravel's own migrate:fresh --force convention.
 */
final class RestoreBackupCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'crm:restore-backup {path} {--force}';

    protected $description = 'Restore a full database backup written by crm:backup, overwriting the current database (BACKEND_BRIEF Z-7.3)';

    public function __construct(private readonly Snapshotter $snapshotter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->confirmToProceed('This will overwrite every table in the current database.')) {
            return self::FAILURE;
        }

        $path = $this->stringArgument('path');

        $this->info("Restoring from [{$path}]...");

        try {
            $this->snapshotter->restoreBackup($path);
        } catch (SnapshotFailed $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restore complete.');

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
