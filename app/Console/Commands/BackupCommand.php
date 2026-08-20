<?php

namespace App\Console\Commands;

use App\Support\SchemaManager\SnapshotFailed;
use App\Support\SchemaManager\Snapshotter;
use Illuminate\Console\Command;

/**
 * Z-7.3 — the backup half of "Backups and cutover runbook." A full
 * mysqldump (structure + data, every table) to the configured backup disk,
 * scheduled nightly (routes/console.php) per BACKEND_BRIEF's own
 * open-question default ("nightly dump to object storage").
 */
final class BackupCommand extends Command
{
    protected $signature = 'crm:backup';

    protected $description = 'Full database backup to the configured backup disk (BACKEND_BRIEF Z-7.3)';

    public function __construct(private readonly Snapshotter $snapshotter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Backing up to disk ['.$this->snapshotter->backupDisk().']...');

        try {
            $path = $this->snapshotter->backup();
        } catch (SnapshotFailed $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup written: {$path}");

        return self::SUCCESS;
    }
}
