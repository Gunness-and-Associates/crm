<?php

namespace App\Support\Etl;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * One per target entity (BACKEND_BRIEF §13's load order). Each transformer reads
 * from the read-only `legacy` connection and produces attributes for its target
 * Eloquent model — `crm:migrate-legacy` handles the shared batching/transaction/
 * idempotency/dry-run mechanics, never duplicated per transformer.
 */
interface LegacyTransformer
{
    /**
     * Unique key used with `--only=` and in progress output — the load-order
     * position is defined by MigrateLegacyCommand::TRANSFORMERS, not here.
     */
    public function key(): string;

    /**
     * A query against the `legacy` connection, ordered by `id` for stable
     * resumability. `$fromId` (nullable) is `--from-id`'s value.
     */
    public function query(?string $fromId): Builder;

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string;

    /**
     * Transform one legacy row into target attributes, including `id` (the
     * source id is always preserved — BACKEND_BRIEF §13's idempotency rule).
     * Returns null to skip the row (logged as skipped, not an error).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public function transform(array $row): ?array;
}
