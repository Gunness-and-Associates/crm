<?php

namespace App\Support;

use App\Models\Metadata\Change;
use App\Support\SchemaManager\Snapshotter;
use Illuminate\Support\Facades\Storage;

/**
 * Per-installation limit (Z-3.3): old mysqldump snapshots are the heavy, ever-growing
 * part of the change log — the `changes` row itself is a cheap, permanent audit trail
 * and is never deleted here. Frees snapshot_disk storage; leaves history intact.
 */
final class ChangeLogPruner
{
    public function __construct(private readonly Snapshotter $snapshotter) {}

    public function pruneSnapshots(?int $days = null): int
    {
        $days = $days ?? $this->configInt('schema-manager.snapshot_retention_days', 30);
        $cutoff = now()->subDays($days);

        $changes = Change::query()
            ->whereNotNull('snapshot_path')
            ->where('created_at', '<', $cutoff)
            ->get();

        $pruned = 0;
        foreach ($changes as $change) {
            $path = $change->snapshot_path;
            if ($path === null) {
                continue;
            }

            Storage::disk($this->snapshotter->disk())->delete($path);
            $change->update(['snapshot_path' => null]);
            $pruned++;
        }

        return $pruned;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
