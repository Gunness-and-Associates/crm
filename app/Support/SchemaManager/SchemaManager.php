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
 * Scope note: plan()/apply() fully support the 'add' and 'delete' (soft) actions.
 * Physical 'modify' (safe widening / guarded narrowing) is Z-3.1 — validate() already
 * classifies the change via the contract's type_change_matrix so that work slots in
 * without revisiting this class.
 */
final class SchemaManager
{
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

            $errors = [...$errors, ...$this->validateType($r)];
        } elseif (in_array($r->action, ['modify', 'delete'], true)) {
            if ($existing === null) {
                $errors[] = "Field [{$r->name}] does not exist on module [{$r->moduleKey}].";
            } elseif ($existing->is_system) {
                $errors[] = "Field [{$r->name}] is a system field and cannot be changed.";
            }

            if ($r->action === 'modify' && $existing !== null && $r->type !== null) {
                $class = $this->contract->typeChangeClass($existing->type, $r->type);
                if ($class === 'blocked') {
                    $errors[] = "Changing [{$r->name}] from {$existing->type} to {$r->type} is blocked.";
                } elseif ($class === 'requires_confirmation' && ! $r->confirmLossy) {
                    $errors[] = "Changing [{$r->name}] from {$existing->type} to {$r->type} requires confirm_lossy.";
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
            $length = $this->intOption($r, 'length', $this->contract->lengthDefault($type));
            $min = $this->contract->lengthMin($type);
            $max = $this->contract->lengthMax($type);
            if ($length < $min || $length > $max) {
                $errors[] = "Length [{$length}] outside the allowed range [{$min}, {$max}].";
            }
        }

        if ($type === 'decimal') {
            $precision = $this->intOption($r, 'precision', $this->contract->precisionDefault($type));
            $scale = $this->intOption($r, 'scale', $this->contract->scaleDefault($type));
            if ($precision > $this->contract->precisionMax($type)) {
                $errors[] = "Precision [{$precision}] exceeds the contract limit.";
            }
            if ($scale > $this->contract->scaleMax($type)) {
                $errors[] = "Scale [{$scale}] exceeds the contract limit.";
            }
        }

        return $errors;
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

        // action === 'add'
        $baseTable = (string) $module->table_name;
        $table = $module->is_custom ? $baseTable : $baseTable.'_custom';

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

                $field = DB::transaction(function () use ($plan) {
                    if ($plan->request->action === 'add') {
                        return Field::create($plan->metadataAttributes);
                    }

                    // delete: soft-delete the metadata row, keep the column (BACKEND_BRIEF §6.5).
                    $module = Module::query()->where('key', $plan->request->moduleKey)->firstOrFail();
                    $field = Field::query()
                        ->where('module_id', $module->id)
                        ->where('name', $plan->request->name)
                        ->firstOrFail();
                    $field->delete();

                    return $field;
                });

                $change = Change::create([
                    'actor_id' => $actorId,
                    'kind' => "field.{$plan->request->action}",
                    'target_module' => $plan->request->moduleKey,
                    'target_field' => $plan->request->name,
                    'payload' => $plan->metadataAttributes,
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
                $module = Module::query()->where('key', $change->target_module)->first();
                if ($module !== null) {
                    Field::query()->withTrashed()
                        ->where('module_id', $module->id)
                        ->where('name', $change->target_field)
                        ->each(fn (Field $f) => $f->forceDelete());
                }
            }

            $change->update(['status' => 'rolled_back', 'reviewer_id' => $actorId]);
            $this->repository->bump();

            return new ChangeResult(success: true, changeId: $change->id, snapshotPath: $change->snapshot_path);
        } finally {
            $lock->release();
        }
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
