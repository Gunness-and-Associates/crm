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
     * @param  list<string>  $tables
     */
    private function dump(array $tables, string $disk, string $pathPrefix): string
    {
        $connection = $this->connectionConfig();

        $path = "{$pathPrefix}/".now()->format('Ymd_His').'_'.Str::random(8).'.sql';

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

        Storage::disk($disk)->put($path, $process->getOutput());

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
