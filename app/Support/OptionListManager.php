<?php

namespace App\Support;

use App\Models\Metadata\Change;
use App\Models\Metadata\Field;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Support\SchemaManager\ConcurrentSchemaChange;
use App\Support\SchemaManager\Snapshotter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persistence and validation for option lists and their items (Z-3.2). A system-frozen
 * list (BACKEND_BRIEF open question 9 — lead_vertical, lead_stage) never accepts item
 * changes here, regardless of confirm_lossy.
 */
final class OptionListManager
{
    public function __construct(
        private readonly Snapshotter $snapshotter,
        private readonly MetadataRepository $repository,
    ) {}

    public function addItem(string $listKey, string $value, string $label, ?int $sortOrder = null, ?string $actorId = null): OptionItem
    {
        $list = $this->findList($listKey);
        $errors = $this->guardEditable($list);

        if ($list !== null && OptionItem::query()->where('option_list_id', $list->id)->where('value', $value)->exists()) {
            $errors[] = "Option [{$value}] already exists on list [{$listKey}].";
        }

        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        /** @var OptionList $list */
        $item = OptionItem::create([
            'option_list_id' => $list->id,
            'value' => $value,
            'label' => $label,
            'sort_order' => $sortOrder ?? ($this->maxSortOrder($list) + 1),
        ]);

        Change::create([
            'actor_id' => $actorId,
            'kind' => 'option.added',
            'target_module' => $listKey,
            'target_field' => $value,
            'payload' => ['before' => null, 'after' => $item->only(['value', 'label', 'sort_order'])],
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        $this->repository->bump();

        return $item;
    }

    public function removeItem(string $listKey, string $value, bool $confirmLossy = false, ?string $actorId = null): void
    {
        $list = $this->findList($listKey);
        $errors = $this->guardEditable($list);

        $item = $list === null ? null : OptionItem::query()
            ->where('option_list_id', $list->id)
            ->where('value', $value)
            ->first();

        if ($list !== null && $item === null) {
            $errors[] = "Option [{$value}] does not exist on list [{$listKey}].";
        }

        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        /** @var OptionList $list */
        /** @var OptionItem $item */
        $tables = $this->tablesUsing($list, $value);

        if ($tables !== [] && ! $confirmLossy) {
            throw new MetadataValidationException([
                "Option [{$value}] is in use on [".implode(', ', $tables).'] and requires confirm_lossy to remove.',
            ]);
        }

        $lock = Cache::lock('crm:schema', $this->configInt('schema-manager.lock_timeout_seconds', 10));
        if (! $lock->get()) {
            throw new ConcurrentSchemaChange('Another schema change is already in progress.');
        }

        try {
            $before = $item->only(['value', 'label', 'sort_order']);
            $snapshotPath = $tables !== [] ? $this->snapshotter->snapshot($tables) : null;

            $item->delete();

            Change::create([
                'actor_id' => $actorId,
                'kind' => 'option.removed',
                'target_module' => $listKey,
                'target_field' => $value,
                'payload' => ['before' => $before, 'after' => null, 'affected_tables' => $tables],
                'status' => 'applied',
                'snapshot_path' => $snapshotPath,
                'applied_at' => now(),
            ]);

            $this->repository->bump();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $orderedValues  every value currently on the list, in the new order
     */
    public function reorderItems(string $listKey, array $orderedValues, ?string $actorId = null): void
    {
        $list = $this->findList($listKey);
        $errors = $this->guardEditable($list);

        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        /** @var OptionList $list */
        $existing = OptionItem::query()->where('option_list_id', $list->id)->get()->keyBy('value');

        if ($existing->keys()->sort()->values()->all() !== collect($orderedValues)->sort()->values()->all()) {
            throw new MetadataValidationException(["The given values do not match the current items on list [{$listKey}] exactly."]);
        }

        $before = $existing->sortBy('sort_order')->keys()->values()->all();

        foreach ($orderedValues as $index => $value) {
            $existing->get($value)?->update(['sort_order' => $index]);
        }

        Change::create([
            'actor_id' => $actorId,
            'kind' => 'option.reordered',
            'target_module' => $listKey,
            'payload' => ['before' => $before, 'after' => $orderedValues],
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        $this->repository->bump();
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function findList(string $listKey): ?OptionList
    {
        return OptionList::query()->where('key', $listKey)->first();
    }

    /**
     * @return list<string>
     */
    private function guardEditable(?OptionList $list): array
    {
        if ($list === null) {
            return ['Option list does not exist.'];
        }

        if ($list->is_system) {
            return ["Option list [{$list->key}] is system-locked and cannot be changed."];
        }

        return [];
    }

    private function maxSortOrder(OptionList $list): int
    {
        $max = OptionItem::query()->where('option_list_id', $list->id)->max('sort_order');

        return is_numeric($max) ? (int) $max : -1;
    }

    /**
     * Every real table that stores this option list's value somewhere (enum: a plain
     * column match; multienum: a JSON-array containment match).
     *
     * @return list<string>
     */
    private function tablesUsing(OptionList $list, string $value): array
    {
        $tables = [];

        $fields = Field::query()->with('module')->where('option_list_id', $list->id)->get();

        foreach ($fields as $field) {
            $module = $field->module;
            if ($module === null || $module->table_name === null) {
                continue;
            }

            $table = $module->is_custom ? $module->table_name : $module->table_name.'_custom';
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $field->name)) {
                continue;
            }

            $exists = $field->type === 'multienum'
                ? DB::table($table)->whereJsonContains($field->name, $value)->exists()
                : DB::table($table)->where($field->name, $value)->exists();

            if ($exists) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }
}
