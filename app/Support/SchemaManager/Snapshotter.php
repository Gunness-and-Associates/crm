<?php

namespace App\Support\SchemaManager;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Dumps structure + data of the affected table(s) before any DDL runs, to the
 * configured snapshot disk. Rollback restores from this dump; SchemaManager
 * never attempts an automatic partial rollback of DDL itself (BACKEND_BRIEF §6.2).
 */
final class Snapshotter
{
    public function disk(): string
    {
        return $this->configString('schema-manager.snapshot_disk', 'local');
    }

    /**
     * A full-database backup is the same mysqldump mechanism with an empty
     * table list (mysqldump's own default when given none is "every table")
     * and its own disk/path — kept apart from schema-change snapshots so a
     * nightly backup is never mistaken for one, and so ChangeLogPruner's
     * change-row-driven cleanup (which only ever deletes a path a `Change`
     * row references) can never touch it.
     *
     * @return string the relative backup path (on $this->backupDisk())
     */
    public function backup(): string
    {
        return $this->dump([], $this->backupDisk(), 'backups');
    }

    public function backupDisk(): string
    {
        return $this->configString('backup.disk', $this->disk());
    }

    public function restoreBackup(string $path): void
    {
        $this->restoreFrom($path, $this->backupDisk());
    }

    /**
     * Writes an already-captured dump (captureDump()) to the backup disk
     * under a caller-chosen path prefix instead of the shared "backups/"
     * one -- Z-8.4's per-tenant backup keeps each tenant's dumps
     * distinguishable by path rather than by timestamp and random suffix
     * alone. Kept as a separate step from captureDump() so the caller can
     * capture while a tenant's connection is active and write only after
     * reverting to central -- see captureDump()'s own docblock for why.
     *
     * @return string the relative path written (on backupDisk())
     */
    public function storeDump(string $sql, string $pathPrefix): string
    {
        $path = "{$pathPrefix}/".now()->format('Ymd_His').'_'.Str::random(8).'.sql';

        Storage::disk($this->backupDisk())->put($path, $sql);

        return $path;
    }

    /**
     * @param  list<string>  $tables
     * @return string the relative snapshot path (on $this->disk())
     */
    public function snapshot(array $tables): string
    {
        return $this->dump($tables, $this->disk(), 'schema-snapshots');
    }

    public function restore(string $path): void
    {
        $this->restoreFrom($path, $this->disk());
    }

    /**
     * The mysqldump call in isolation, with no storage write -- the piece
     * that must run while a specific tenant's connection is active. Exposed
     * for callers (Z-8.4's per-tenant backup) that need the dump captured
     * inside a tenant's context but written to storage after reverting to
     * central: `local`/`public` are tenant-suffixed disks
     * (config/tenancy.php), so a Storage::put() made while a tenant is still
     * initialized would land under that tenant's own suffixed subtree
     * instead of the shared backup location every tenant's dumps belong on.
     *
     * @param  list<string>  $tables
     */
    public function captureDump(array $tables = []): string
    {
        $connection = $this->connectionConfig();

        $command = [
            $this->configString('schema-manager.mysqldump_binary', 'mysqldump'),
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--no-tablespaces',
            '--skip-lock-tables',
            $connection['database'],
            ...$tables,
        ];

        // Symfony Process defaults to a 60s timeout -- fine for a schema-manager
        // snapshot (one or two tables), but a full-database dump/restore has no
        // ceiling that's safe to guess at real production data volumes.
        $process = new Process($command, null, ['MYSQL_PWD' => $connection['password']]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful() || $process->getOutput() === '') {
            throw new SnapshotFailed('mysqldump failed: '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * @param  list<string>  $tables
     */
    private function dump(array $tables, string $disk, string $pathPrefix): string
    {
        $sql = $this->captureDump($tables);

        $path = "{$pathPrefix}/".now()->format('Ymd_His').'_'.Str::random(8).'.sql';

        Storage::disk($disk)->put($path, $sql);

        return $path;
    }

    private function restoreFrom(string $path, string $disk): void
    {
        $sql = Storage::disk($disk)->get($path);
        if ($sql === null) {
            throw new SnapshotFailed("Snapshot not found: {$path}");
        }

        $connection = $this->connectionConfig();

        $command = [
            $this->configString('schema-manager.mysql_binary', 'mysql'),
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            $connection['database'],
        ];

        $process = new Process($command, null, ['MYSQL_PWD' => $connection['password']]);
        $process->setTimeout(null);
        $process->setInput($sql);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new SnapshotFailed('Snapshot restore failed: '.$process->getErrorOutput());
        }
    }

    /**
     * @return array{host: string, port: string, username: string, password: string, database: string}
     */
    private function connectionConfig(): array
    {
        $default = config('database.default');
        $defaultName = is_string($default) ? $default : 'mysql';

        $connection = config("database.connections.{$defaultName}");
        if (! is_array($connection)) {
            throw new SnapshotFailed('No database connection configured.');
        }

        return [
            'host' => $this->str($connection['host'] ?? null, '127.0.0.1'),
            'port' => $this->str($connection['port'] ?? null, '3306'),
            'username' => $this->str($connection['username'] ?? null),
            'password' => $this->str($connection['password'] ?? null),
            'database' => $this->str($connection['database'] ?? null),
        ];
    }

    private function configString(string $key, string $default): string
    {
        return $this->str(config($key), $default);
    }

    private function str(mixed $value, string $default = ''): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }
}
