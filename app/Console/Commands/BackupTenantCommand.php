<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\SchemaManager\SnapshotFailed;
use App\Support\SchemaManager\Snapshotter;
use Illuminate\Console\Command;

/**
 * Z-8.4 (BACKEND_BRIEF_ZAIN.md §14 step 8: "per-tenant backup and export").
 * A full mysqldump of one tenant's own database -- the same mechanism as
 * Z-7.3's crm:backup, just run inside that tenant's context instead of the
 * central/default one, and under a path that makes each tenant's dumps
 * distinguishable from every other tenant's on the shared backup disk.
 *
 * Doubles as tenant data export: a complete, portable SQL dump of a single
 * tenant's data is both a restorable backup and everything a departing
 * customer's data would need to hand over -- there's no separate, smaller
 * "export" format specified anywhere in the brief, and inventing a second
 * (JSON/CSV) format nobody asked for would be scope creep this task doesn't
 * call for.
 *
 * Loops tenants manually rather than via tenancy()->runForMultiple(): each
 * tenant's dump is captured while its connection is active, then ended and
 * written to storage before moving to the next tenant -- both so a
 * multi-tenant run never holds more than one dump in memory at a time, and
 * because a write made while still inside a tenant's context would land
 * under that tenant's own tenant-suffixed `local`/`public` disk subtree
 * (config/tenancy.php) instead of the shared backup location every
 * tenant's dumps belong on -- see Snapshotter::captureDump()'s own docblock.
 */
final class BackupTenantCommand extends Command
{
    protected $signature = 'crm:backup-tenant {--tenants=* : Tenant ID(s) to back up. Default: all tenants}';

    protected $description = 'Full per-tenant database backup/export (BACKEND_BRIEF Z-8.4)';

    public function handle(): int
    {
        $failures = 0;
        $snapshotter = app(Snapshotter::class);

        $tenantIds = array_values(array_filter($this->option('tenants'), 'is_string'));
        $tenants = Tenant::query()->when($tenantIds !== [], fn ($q) => $q->whereIn('id', $tenantIds))->cursor();

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $sql = $snapshotter->captureDump();
            } catch (SnapshotFailed $e) {
                $failures++;
                $this->error("Tenant {$tenant->id}: backup failed -- {$e->getMessage()}");

                continue;
            } finally {
                tenancy()->end();
            }

            $path = $snapshotter->storeDump($sql, "backups/tenant-{$tenant->id}");
            $this->info("Tenant {$tenant->id}: backup written to {$path}");
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
