<?php

namespace App\Support\SchemaManager;

use App\Models\Metadata\Change;
use App\Models\Metadata\Field;
use App\Models\Metadata\Module;
use App\Support\FieldTypeContract;
use App\Support\MetadataRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe runtime DDL. The single most dangerous class in the system (BACKEND_BRIEF §6) —
 * every ALTER/CREATE the app ever runs against a tenant database goes through here.
 *
 * plan()/apply() support 'add', 'modify' and 'delete' (soft). validate() classifies
 * every 'modify' — cross-type via the contract's type_change_matrix, same-type
 * parameter changes (length/precision/scale/required) by comparing old vs new — into
 * safe | requires_confirmation | blocked before any DDL is built (Z-3.1).
 */
final class SchemaManager
{
    /**
     * The Field attribute keys a 'modify' can touch — captured as the change log's
     * "before" state, and what rollback() restores for a rolled-back modify.
     */
    private const MODIFIABLE_ATTRIBUTE_KEYS = [
        'type', 'required', 'default_value', 'help', 'comments', 'max_length',
        'precision', 'scale', 'option_list_id', 'related_module_id',
        'related_display_field', 'filterable', 'sortable',
    ];

    public function __construct(
        private readonly FieldTypeContract $contract,
        private readonly Snapshotter $snapshotter,
        private readonly MetadataRepository $repository,
    ) {}

    /**
     * @return list<string> validation errors; empty means the request may be planned
     */
    public function validate(FieldChangeRequest $r): array
    {
        $errors = [];

        // Rejected unconditionally, regardless of action (BACKEND_BRIEF §6.3 last bullet).
        if ($r->name === 'tenant_id') {
            $errors[] = 'A field named tenant_id is never permitted.';
        }

        if (! preg_match('/'.$this->contract->fieldNamePattern().'/', $r->name)) {
            $errors[] = "Field name [{$r->name}] does not match the required pattern.";
        }

        if (in_array($r->name, $this->contract->reservedFieldNames(), true)) {
            $errors[] = "Field name [{$r->name}] is reserved.";
        }

        $module = Module::query()->where('key', $r->moduleKey)->first();
        if ($module === null) {
            $errors[] = "Module [{$r->moduleKey}] does not exist.";

            return $errors; // nothing further can be validated without a module
        }

        if ($module->is_system) {
            $errors[] = "Module [{$r->moduleKey}] is system-locked and cannot be changed.";
        }

        $existing = Field::query()->withTrashed()
            ->where('module_id', $module->id)
            ->where('name', $r->name)
            ->first();

        if ($r->action === 'add') {
            if ($existing !== null) {
                $errors[] = "Field [{$r->name}] already exists on module [{$r->moduleKey}] (including soft-deleted).";
            }

            $count = Field::query()->where('module_id', $module->id)->count();
            $max = $this->configInt('schema-manager.max_custom_fields_per_module', 150);
            if ($count >= $max) {
                $errors[] = "Module [{$r->moduleKey}] has reached its field ceiling of {$max}.";
            }

            $total = Field::query()->where('is_custom', true)->count();
            $totalMax = $this->configInt('schema-manager.max_custom_fields_total', 1000);
            if ($total >= $totalMax) {
                $errors[] = "This installation has reached its custom field ceiling of {$totalMax}.";
            }

            $errors = [...$errors, ...$this->validateType($r)];
        } elseif (in_array($r->action, ['modify', 'delete'], true)) {
            if ($existing === null) {
                $errors[] = "Field [{$r->name}] does not exist on module [{$r->moduleKey}].";
            } elseif ($existing->is_system) {
                $errors[] = "Field [{$r->name}] is a system field and cannot be changed.";
            }

            if ($r->action === 'modify' && $existing !== null) {
                $toType = (string) ($r->type ?? $existing->type);

                if ($r->type !== null && $r->type !== $existing->type) {
                    $errors = [...$errors, ...$this->validateType($r)];
                } elseif ($toType === 'text' && $r->option('length') !== null) {
                    $errors = [...$errors, ...$this->validateTextLength($toType, $r)];
                } elseif ($toType === 'decimal' && ($r->option('precision') !== null || $r->option('scale') !== null)) {
                    $errors = [...$errors, ...$this->validateDecimalPrecision($toType, $r)];
                }

                $class = $this->classifyModify($existing, $r);
                if ($class === 'blocked') {
                    $errors[] = "Changing [{$r->name}] from {$existing->type} to {$toType} is blocked.";
                } elseif ($class === 'requires_confirmation' && ! $r->confirmLossy) {
                    $errors[] = "Changing [{$r->name}] from {$existing->type} to {$toType} requires confirm_lossy.";
                }
            }
        } else {
            $errors[] = "Unsupported action [{$r->action}].";
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateType(FieldChangeRequest $r): array
    {
        $errors = [];

        if ($r->type === null || ! $this->contract->exists($r->type)) {
            $errors[] = "Field type [{$r->type}] is not in the contract.";

            return $errors;
        }

        $type = $r->type;

        foreach ($this->contract->requiredOptions($type) as $requiredOption) {
            if ($r->option($requiredOption) === null) {
                $errors[] = "Field type [{$type}] requires option [{$requiredOption}].";
            }
        }

        if ($type === 'text') {
            $errors = [...$errors, ...$this->validateTextLength($type, $r)];
        }

        if ($type === 'decimal') {
            $errors = [...$errors, ...$this->validateDecimalPrecision($type, $r)];
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateTextLength(string $type, FieldChangeRequest $r): array
    {
        $errors = [];

        $length = $this->intOption($r, 'length', $this->contract->lengthDefault($type));
        $min = $this->contract->lengthMin($type);
        $max = $this->contract->lengthMax($type);
        if ($length < $min || $length > $max) {
            $errors[] = "Length [{$length}] outside the allowed range [{$min}, {$max}].";
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateDecimalPrecision(string $type, FieldChangeRequest $r): array
    {
        $errors = [];

        $precision = $this->intOption($r, 'precision', $this->contract->precisionDefault($type));
        $scale = $this->intOption($r, 'scale', $this->contract->scaleDefault($type));
        if ($precision > $this->contract->precisionMax($type)) {
            $errors[] = "Precision [{$precision}] exceeds the contract limit.";
        }
        if ($scale > $this->contract->scaleMax($type)) {
            $errors[] = "Scale [{$scale}] exceeds the contract limit.";
        }

        return $errors;
    }

    /**
     * Classify a 'modify' request as safe | requires_confirmation | blocked | unknown,
     * covering both cross-type changes (via the contract's matrix) and same-type
     * parameter changes (length/precision/scale/required) the matrix expresses as
     * free text rather than a `from -> to` pair.
     */
    private function classifyModify(Field $existing, FieldChangeRequest $r): string
    {
        $fromType = (string) $existing->type;
        $toType = (string) ($r->type ?? $fromType);

        if ($fromType !== $toType) {
            return $this->contract->typeChangeClass($fromType, $toType);
        }

        if ($toType === 'text' && $r->option('length') !== null) {
            $newLength = $this->intOption($r, 'length', $this->contract->lengthDefault($toType));
            $oldLength = $existing->max_length ?? $this->contract->lengthDefault($toType);

            return $newLength >= $oldLength ? 'safe' : 'requires_confirmation';
        }

        if ($toType === 'decimal' && ($r->option('precision') !== null || $r->option('scale') !== null)) {
            $oldPrecision = $existing->precision ?? $this->contract->precisionDefault($toType);
            $oldScale = $existing->scale ?? $this->contract->scaleDefault($toType);
            $newPrecision = $this->intOption($r, 'precision', $oldPrecision);
            $newScale = $this->intOption($r, 'scale', $oldScale);

            return ($newPrecision >= $oldPrecision && $newScale >= $oldScale) ? 'safe' : 'requires_confirmation';
        }

        if ($r->option('required') === true && ! $existing->required) {
            return 'requires_confirmation';
        }

        $relatedModuleId = $this->stringOption($r, 'related_module_id');
        if ($toType === 'relate' && $relatedModuleId !== null && $relatedModuleId !== $existing->related_module_id) {
            return $this->relateColumnHasData($existing) ? 'blocked' : 'safe';
        }

        return 'safe';
    }

    private function relateColumnHasData(Field $existing): bool
    {
        $module = $existing->module;
        $tableName = $module?->table_name;
        if ($module === null || $tableName === null) {
            return false;
        }

        $table = $module->is_custom ? $tableName : $tableName.'_custom';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $existing->name)) {
            return false;
        }

        return DB::table($table)->whereNotNull($existing->name)->exists();
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function intOption(FieldChangeRequest $r, string $key, int $default): int
    {
        $value = $r->option($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function nullableIntOption(FieldChangeRequest $r, string $key): ?int
    {
        $value = $r->option($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOption(FieldChangeRequest $r, string $key): ?string
    {
        $value = $r->option($key);

        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    /**
     * No side effects — returns the SQL that apply() would execute, plus warnings.
     */
    public function plan(FieldChangeRequest $r): ChangePlan
    {
        $errors = $this->validate($r);
        if ($errors !== []) {
            throw new SchemaValidationException($errors);
        }

        $module = Module::query()->where('key', $r->moduleKey)->firstOrFail();

        if ($r->action === 'delete') {
            return new ChangePlan(request: $r, table: (string) $module->table_name, ddl: []);
        }

        $baseTable = (string) $module->table_name;
        $table = $module->is_custom ? $baseTable : $baseTable.'_custom';

        if ($r->action === 'modify') {
            return $this->planModify($r, $module, $table);
        }

        // action === 'add'
        $ddl = [];
        if (! Schema::hasTable($table)) {
            $ddl[] = $this->createSidecarSql($table);
        }

        $columnSql = $this->buildColumnDefinition($r, (string) $r->type);
        $ddl[] = sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $this->ident($table),
            $this->ident($r->name),
            $columnSql,
        );

        if ($this->contract->indexable((string) $r->type)) {
            $ddl[] = sprintf(
                'ALTER TABLE %s ADD INDEX %s (%s)',
                $this->ident($table),
                $this->ident('idx_'.$r->name),
                $this->ident($r->name),
            );
        }

        return new ChangePlan(
            request: $r,
            table: $table,
            ddl: $ddl,
            metadataAttributes: [
                'module_id' => $module->id,
                'name' => $r->name,
                'type' => $r->type,
                'label_key' => 'LBL_'.strtoupper($r->name),
                'storage' => 'column',
                'required' => (bool) $r->option('required', false),
                'default_value' => $this->stringOption($r, 'default'),
                'help' => $this->stringOption($r, 'help'),
                'comments' => $this->stringOption($r, 'comments'),
                'max_length' => $this->nullableIntOption($r, 'length'),
                'precision' => $this->nullableIntOption($r, 'precision'),
                'scale' => $this->nullableIntOption($r, 'scale'),
                'option_list_id' => $this->stringOption($r, 'option_list_id'),
                'related_module_id' => $this->stringOption($r, 'related_module_id'),
                'related_display_field' => $this->stringOption($r, 'related_display_field'),
                'filterable' => $this->contract->filterable((string) $r->type),
                'sortable' => $this->contract->sortable((string) $r->type),
                'is_custom' => true,
            ],
        );
    }

    private function planModify(FieldChangeRequest $r, Module $module, string $table): ChangePlan
    {
        $existing = Field::query()
            ->where('module_id', $module->id)
            ->where('name', $r->name)
            ->firstOrFail();

        $type = (string) ($r->type ?? $existing->type);

        $columnSql = $this->buildModifyColumnDefinition($r, $existing, $type);
        $ddl = [sprintf(
            'ALTER TABLE %s MODIFY COLUMN %s %s',
            $this->ident($table),
            $this->ident($r->name),
            $columnSql,
        )];

        $requiredOption = $r->option('required');
        $required = is_bool($requiredOption) ? $requiredOption : (bool) $existing->required;

        return new ChangePlan(
            request: $r,
            table: $table,
            ddl: $ddl,
            metadataAttributes: [
                'type' => $type,
                'required' => $required,
                'default_value' => $this->stringOption($r, 'default') ?? $existing->default_value,
                'help' => $this->stringOption($r, 'help') ?? $existing->help,
                'comments' => $this->stringOption($r, 'comments') ?? $existing->comments,
                'max_length' => $this->nullableIntOption($r, 'length') ?? $existing->max_length,
                'precision' => $this->nullableIntOption($r, 'precision') ?? $existing->precision,
                'scale' => $this->nullableIntOption($r, 'scale') ?? $existing->scale,
                'option_list_id' => $this->stringOption($r, 'option_list_id') ?? $existing->option_list_id,
                'related_module_id' => $this->stringOption($r, 'related_module_id') ?? $existing->related_module_id,
                'related_display_field' => $this->stringOption($r, 'related_display_field') ?? $existing->related_display_field,
                'filterable' => $this->contract->filterable($type),
                'sortable' => $this->contract->sortable($type),
            ],
        );
    }

    public function apply(ChangePlan $plan, ?string $actorId): ChangeResult
    {
        $errors = $this->validate($plan->request);
        if ($errors !== []) {
            throw new SchemaValidationException($errors);
        }

        $lock = Cache::lock('crm:schema', $this->configInt('schema-manager.lock_timeout_seconds', 10));
        if (! $lock->get()) {
            throw new ConcurrentSchemaChange('Another schema change is already in progress.');
        }

        try {
            // Nothing to protect if the target table doesn't exist yet — a CREATE TABLE
            // (via createSidecarSql, prepended to $ddl) can't destroy data that never existed.
            $snapshotPath = ($plan->ddl !== [] && Schema::hasTable($plan->table))
                ? $this->snapshotter->snapshot([$plan->table])
                : null;

            $executed = [];
            try {
                foreach ($plan->ddl as $statement) {
                    $executed[] = $statement;
                    DB::statement($statement);
                }

                $before = null;
                $field = DB::transaction(function () use ($plan, &$before) {
                    if ($plan->request->action === 'add') {
                        return Field::create($plan->metadataAttributes);
                    }

                    $module = Module::query()->where('key', $plan->request->moduleKey)->firstOrFail();
                    $field = Field::query()
                        ->where('module_id', $module->id)
                        ->where('name', $plan->request->name)
                        ->firstOrFail();

                    $before = $field->only(self::MODIFIABLE_ATTRIBUTE_KEYS);

                    if ($plan->request->action === 'modify') {
                        $field->update($plan->metadataAttributes);

                        return $field;
                    }

                    // delete: soft-delete the metadata row, keep the column (BACKEND_BRIEF §6.5).
                    $field->delete();

                    return $field;
                });

                $change = Change::create([
                    'actor_id' => $actorId,
                    'kind' => "field.{$plan->request->action}",
                    'target_module' => $plan->request->moduleKey,
                    'target_field' => $plan->request->name,
                    'payload' => [
                        'before' => $before,
                        'after' => $plan->request->action === 'delete' ? null : $plan->metadataAttributes,
                    ],
                    'status' => 'applied',
                    'ddl' => implode("\n", $executed),
                    'snapshot_path' => $snapshotPath,
                    'applied_at' => now(),
                ]);

                $this->repository->bump();

                return new ChangeResult(success: true, changeId: $change->id, snapshotPath: $snapshotPath);
            } catch (\Throwable $e) {
                Change::create([
                    'actor_id' => $actorId,
                    'kind' => "field.{$plan->request->action}",
                    'target_module' => $plan->request->moduleKey,
                    'target_field' => $plan->request->name,
                    'status' => 'failed',
                    'ddl' => implode("\n", $executed),
                    'snapshot_path' => $snapshotPath,
                ]);

                return new ChangeResult(success: false, snapshotPath: $snapshotPath, error: $e->getMessage());
            }
        } finally {
            $lock->release();
        }
    }

    public function rollback(string $changeId, string $actorId): ChangeResult
    {
        $change = Change::query()->findOrFail($changeId);

        if ($change->snapshot_path === null) {
            throw new SnapshotFailed('This change has no snapshot to roll back to.');
        }

        $lock = Cache::lock('crm:schema', $this->configInt('schema-manager.lock_timeout_seconds', 10));
        if (! $lock->get()) {
            throw new ConcurrentSchemaChange('Another schema change is already in progress.');
        }

        try {
            $this->snapshotter->restore($change->snapshot_path);

            if ($change->target_module !== null && $change->target_field !== null) {
                $this->rollbackFieldMetadata($change);
            }

            $change->update(['status' => 'rolled_back', 'reviewer_id' => $actorId]);
            $this->repository->bump();

            return new ChangeResult(success: true, changeId: $change->id, snapshotPath: $change->snapshot_path);
        } finally {
            $lock->release();
        }
    }

    /**
     * A rolled-back 'field.add' never should have existed — force-delete the metadata
     * row entirely. A rolled-back 'field.modify' should return to its prior attributes,
     * not disappear — restore them from the change log's "before" state.
     */
    private function rollbackFieldMetadata(Change $change): void
    {
        $module = Module::query()->where('key', $change->target_module)->first();
        if ($module === null) {
            return;
        }

        $field = Field::query()->withTrashed()
            ->where('module_id', $module->id)
            ->where('name', $change->target_field)
            ->first();

        if ($field === null) {
            return;
        }

        if ($change->kind === 'field.add') {
            $field->forceDelete();

            return;
        }

        if ($change->kind === 'field.modify') {
            $payload = $change->payload;
            $before = is_array($payload) ? ($payload['before'] ?? null) : null;
            if (is_array($before)) {
                $field->update($this->stringKeyedArray($before));
            }
        }
    }

    /**
     * @param  array<mixed, mixed>  $array
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Create the {table}_custom sidecar directly (no data at risk in an empty CREATE
     * TABLE, so no snapshot is required). Idempotent.
     */
    public function createSidecar(string $table): void
    {
        $sidecar = str_ends_with($table, '_custom') ? $table : $table.'_custom';

        if (Schema::hasTable($sidecar)) {
            return;
        }

        DB::statement($this->createSidecarSql($sidecar));
        $this->repository->bump();
    }

    private function createSidecarSql(string $sidecar): string
    {
        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id char(36) NOT NULL PRIMARY KEY, created_at timestamp NULL, updated_at timestamp NULL)',
            $this->ident($sidecar),
        );
    }

    private function buildColumnDefinition(FieldChangeRequest $r, string $type): string
    {
        $column = $this->contract->column($type);

        if (str_contains($column, ':length')) {
            $length = $this->intOption($r, 'length', $this->contract->lengthDefault($type));
            $column = str_replace(':length', (string) $length, $column);
        }

        if (str_contains($column, ':precision') || str_contains($column, ':scale')) {
            $precision = $this->intOption($r, 'precision', $this->contract->precisionDefault($type));
            $scale = $this->intOption($r, 'scale', $this->contract->scaleDefault($type));
            $column = str_replace([':precision', ':scale'], [(string) $precision, (string) $scale], $column);
        }

        $required = (bool) $r->option('required', false);
        $nullability = $type === 'bool' ? 'NOT NULL DEFAULT 0' : ($required ? 'NOT NULL' : 'NULL');

        return "{$column} {$nullability}";
    }

    /**
     * Same shape as buildColumnDefinition(), but for 'modify' — length/precision/scale/
     * required fall back to the existing Field's stored values, not the contract default,
     * since an unspecified option means "leave this parameter as it is".
     */
    private function buildModifyColumnDefinition(FieldChangeRequest $r, Field $existing, string $type): string
    {
        $column = $this->contract->column($type);

        if (str_contains($column, ':length')) {
            $length = $this->intOption($r, 'length', $existing->max_length ?? $this->contract->lengthDefault($type));
            $column = str_replace(':length', (string) $length, $column);
        }

        if (str_contains($column, ':precision') || str_contains($column, ':scale')) {
            $precision = $this->intOption($r, 'precision', $existing->precision ?? $this->contract->precisionDefault($type));
            $scale = $this->intOption($r, 'scale', $existing->scale ?? $this->contract->scaleDefault($type));
            $column = str_replace([':precision', ':scale'], [(string) $precision, (string) $scale], $column);
        }

        $requiredOption = $r->option('required');
        $required = is_bool($requiredOption) ? $requiredOption : (bool) $existing->required;
        $nullability = $type === 'bool' ? 'NOT NULL DEFAULT 0' : ($required ? 'NOT NULL' : 'NULL');

        return "{$column} {$nullability}";
    }

    /**
     * The ONLY way an identifier may reach a DDL string (BACKEND_BRIEF §6.4).
     */
    private function ident(string $raw): string
    {
        if (! preg_match('/^[a-z][a-z0-9_]{1,58}$/', $raw)) {
            throw new UnsafeIdentifier($raw);
        }

        return '`'.$raw.'`';
    }
}
