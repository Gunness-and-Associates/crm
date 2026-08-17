<?php

namespace App\Console\Commands;

use App\Support\Etl\LegacyTransformer;
use App\Support\Etl\MigrationResult;
use App\Support\Etl\UserTransformer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * BACKEND_BRIEF §13 — runs locally against a copy, never production.
 *
 *   php artisan crm:migrate-legacy --dry-run        # reports counts, writes nothing
 *   php artisan crm:migrate-legacy --only=companies # one entity
 *   php artisan crm:migrate-legacy                  # idempotent, resumable
 *
 * The load order below matches §13's foreign-key dependency chain. Each
 * transformer owns its own source query and row mapping; this command owns the
 * shared mechanics only: batching (500 rows/transaction), `--from-id` resume,
 * idempotency (upsert keyed on the preserved source id), and dry-run reporting.
 */
final class MigrateLegacyCommand extends Command
{
    protected $signature = 'crm:migrate-legacy {--dry-run} {--only=} {--from-id=}';

    protected $description = 'Migrate the legacy crmga database into the new CRM (BACKEND_BRIEF §13)';

    private const BATCH_SIZE = 500;

    public function handle(UserTransformer $users): int
    {
        /** @var list<LegacyTransformer> $transformers */
        $transformers = [
            $users,
            // Appended in load order as each is built: option lists -> companies ->
            // leads -> students -> assessments -> clients -> affiliates ->
            // newsletter subscribers -> activities -> email addresses -> audit.
        ];

        $only = $this->stringOption('only');
        $dryRun = (bool) $this->option('dry-run');
        $fromId = $this->stringOption('from-id');

        $matched = false;
        foreach ($transformers as $transformer) {
            if ($only !== null && $transformer->key() !== $only) {
                continue;
            }

            $matched = true;
            $this->migrate($transformer, $dryRun, $fromId);
        }

        if (! $matched) {
            $this->error("No transformer matches --only={$only}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function migrate(LegacyTransformer $transformer, bool $dryRun, ?string $fromId): void
    {
        $result = new MigrationResult($transformer->key());
        $modelClass = $transformer->modelClass();

        $transformer->query($fromId)->orderBy('id')->chunk(
            self::BATCH_SIZE,
            function ($rows) use ($transformer, $modelClass, $dryRun, $result): void {
                DB::transaction(function () use ($rows, $transformer, $modelClass, $dryRun, $result): void {
                    foreach ($rows as $row) {
                        $this->migrateRow($this->stringKeyed($row), $transformer, $modelClass, $dryRun, $result);
                    }
                });
            },
        );

        $this->report($result, $dryRun);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  class-string<Model>  $modelClass
     */
    private function migrateRow(array $row, LegacyTransformer $transformer, string $modelClass, bool $dryRun, MigrationResult $result): void
    {
        $result->total++;
        $sourceId = $this->stringValue($row['id'] ?? null);

        try {
            $attributes = $transformer->transform($row);
        } catch (\Throwable $e) {
            $result->recordError($sourceId, $e->getMessage());

            return;
        }

        if ($attributes === null) {
            $result->skipped++;

            return;
        }

        if ($dryRun) {
            return;
        }

        try {
            // updateOrCreate() won't do — it mass-assigns via fill(), which drops
            // 'id' silently since it's never in $fillable, so the "preserve source
            // id, idempotent re-run" rule needs forceFill() instead.
            $id = $attributes['id'] ?? null;
            $found = $modelClass::withoutGlobalScopes()->find($id);
            $model = $found instanceof Model ? $found : new $modelClass;
            $wasNew = ! $found instanceof Model;

            // UserTransformer generates a fresh random "please reset" password
            // every call — re-running the migration must never clobber a real
            // password an admin has since set via the normal reset flow.
            if (! $wasNew) {
                unset($attributes['password']);
            }

            $model->forceFill($attributes)->save();
        } catch (\Throwable $e) {
            $result->recordError($sourceId, $e->getMessage());

            return;
        }

        if ($wasNew) {
            $result->created++;
        } else {
            $result->updated++;
        }
    }

    private function report(MigrationResult $result, bool $dryRun): void
    {
        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info(sprintf(
            '%s%s: total=%d created=%d updated=%d skipped=%d errors=%d',
            $prefix,
            $result->key,
            $result->total,
            $result->created,
            $result->updated,
            $result->skipped,
            count($result->errors),
        ));

        foreach ($result->errors as $error) {
            $this->warn("  [{$result->key}:{$error['id']}] {$error['message']}");
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $row): array
    {
        $array = (array) $row;
        $result = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }
}
