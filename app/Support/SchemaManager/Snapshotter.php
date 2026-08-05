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
     * @param  list<string>  $tables
     * @return string the relative snapshot path (on $this->disk())
     */
    public function snapshot(array $tables): string
    {
        $connection = $this->connectionConfig();

        $path = 'schema-snapshots/'.now()->format('Ymd_His').'_'.Str::random(8).'.sql';

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

        $process = new Process($command, null, ['MYSQL_PWD' => $connection['password']]);
        $process->run();

        if (! $process->isSuccessful() || $process->getOutput() === '') {
            throw new SnapshotFailed('mysqldump failed: '.$process->getErrorOutput());
        }

        Storage::disk($this->disk())->put($path, $process->getOutput());

        return $path;
    }

    public function restore(string $path): void
    {
        $sql = Storage::disk($this->disk())->get($path);
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
