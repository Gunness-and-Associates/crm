<?php

namespace App\Support\SchemaManager;

/**
 * The validated, ready-to-execute result of SchemaManager::plan(). Immutable —
 * apply() re-validates before executing anything in $ddl.
 */
final class ChangePlan
{
    /**
     * @param  list<string>  $ddl  DDL statements, in execution order
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $metadataAttributes  the row to write to `fields` on success
     */
    public function __construct(
        public readonly FieldChangeRequest $request,
        public readonly string $table,
        public readonly array $ddl,
        public readonly array $warnings = [],
        public readonly bool $requiresConfirmation = false,
        public readonly array $metadataAttributes = [],
    ) {}
}
