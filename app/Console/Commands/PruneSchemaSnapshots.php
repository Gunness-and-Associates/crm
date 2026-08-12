<?php

namespace App\Console\Commands;

use App\Support\ChangeLogPruner;
use Illuminate\Console\Command;

class PruneSchemaSnapshots extends Command
{
    protected $signature = 'schema:prune-snapshots {--days=}';

    protected $description = 'Delete mysqldump snapshots older than the retention window, keeping the change log rows';

    public function handle(ChangeLogPruner $pruner): int
    {
        $days = $this->option('days');
        $pruned = $pruner->pruneSnapshots(is_numeric($days) ? (int) $days : null);

        $this->info("Pruned {$pruned} snapshot(s).");

        return self::SUCCESS;
    }
}
