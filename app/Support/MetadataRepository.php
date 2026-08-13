<?php

namespace App\Support;

use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;

/**
 * Compiles the metadata registry into one cached structure, keyed by a version
 * that bumps on any change. One cache read per request drives the whole engine
 * (schema, UI, permissions, API).
 */
final class MetadataRepository
{
    private const VERSION_KEY = 'meta:version';

    /** @var array<string, mixed>|null */
    private ?array $compiledCache = null;

    /** @var array<string, array<string, string>> table => {field name: field type} */
    private array $customFieldDefinitionsByTable = [];

    public function version(): int
    {
        $value = Cache::get(Keys::cache(self::VERSION_KEY), 1);

        return is_numeric($value) ? (int) $value : 1;
    }

    public function bump(): int
    {
        $next = $this->version() + 1;
        Cache::forever(Keys::cache(self::VERSION_KEY), $next);
        $this->compiledCache = null;
        $this->customFieldDefinitionsByTable = [];

        return $next;
    }

    /**
     * The real (storage=column) fields registered against a table — what
     * HasCustomFields needs to know which attributes live in its sidecar. Kept here,
     * not memoized in the trait itself: this repository is the one thing already
     * guaranteed to be freshly re-created per request/test (Z-4.4) — a plain static
     * cache in the trait would survive across requests that share a PHP process
     * (e.g. Pest's test run) with no way to invalidate it against the metadata version.
     *
     * @return array<string, string> field name => field-type key
     */
    public function customFieldDefinitionsForTable(string $table): array
    {
        if (! array_key_exists($table, $this->customFieldDefinitionsByTable)) {
            $this->customFieldDefinitionsByTable[$table] = $this->buildCustomFieldDefinitions($table);
        }

        return $this->customFieldDefinitionsByTable[$table];
    }

    /**
     * Only genuinely Studio-added fields (is_custom) belong in the `_custom` sidecar —
     * a seeded base-table column (leads.vertical, leads.stage, ...) is also registered
     * with storage='column' for its filterable/sortable metadata, but it is a real
     * column on the base table, not a sidecar attribute. Treating it as one would
     * silently divert its value into the sidecar (or drop it, if no sidecar table
     * exists yet) instead of the real column — this is exactly that bug, caught while
     * building Z-5.2, which is the first thing to both seed this metadata and exercise
     * create/update on the same models.
     *
     * @return array<string, string>
     */
    private function buildCustomFieldDefinitions(string $table): array
    {
        $definitions = [];
        $modules = $this->compiled()['modules'] ?? [];

        foreach (is_array($modules) ? $modules : [] as $module) {
            if (! is_array($module) || ($module['table_name'] ?? null) !== $table) {
                continue;
            }

            $fields = $module['fields'] ?? [];
            foreach (is_array($fields) ? $fields : [] as $name => $field) {
                if (! is_array($field) || ($field['storage'] ?? null) !== 'column' || ! ($field['is_custom'] ?? false)) {
                    continue;
                }

                $type = $field['type'] ?? null;
                if (is_string($name) && is_string($type)) {
                    $definitions[$name] = $type;
                }
            }
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    public function compiled(): array
    {
        // MetadataRepository is a singleton (one instance per request) — this memoizes
        // the cache-store round trip itself (Z-4.4), since compiled() is called from
        // several independent places (HasCustomFields per model class, dashboard
        // widgets, ...) that would otherwise each pay for it within the same request.
        if ($this->compiledCache !== null) {
            return $this->compiledCache;
        }

        return $this->compiledCache = Cache::rememberForever(
            Keys::cache('meta:v'.$this->version()),
            fn (): array => $this->compile(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compile(): array
    {
        $modules = Module::query()
            ->with(['fields', 'layouts' => fn (Relation $query): Relation => $query->where('is_published', true)])
            ->get()
            ->keyBy('key')
            ->map(fn (Module $m): array => [
                'key' => $m->key,
                'label' => $m->label,
                'table_name' => $m->table_name,
                'base_type' => $m->base_type,
                'enabled' => $m->enabled,
                'fields' => $m->fields
                    ->keyBy('name')
                    ->map(fn (Field $f): array => [
                        'name' => $f->name,
                        'type' => $f->type,
                        'label_key' => $f->label_key,
                        'storage' => $f->storage,
                        'is_custom' => $f->is_custom,
                        'required' => $f->required,
                        'filterable' => $f->filterable,
                        'sortable' => $f->sortable,
                        'max_length' => $f->max_length,
                        'precision' => $f->precision,
                        'scale' => $f->scale,
                        'option_list_id' => $f->option_list_id,
                        'related_module_id' => $f->related_module_id,
                        'related_display_field' => $f->related_display_field,
                        'default_value' => $f->default_value,
                    ])->all(),
                'layouts' => $m->layouts
                    ->keyBy('view')
                    ->map(fn (Layout $l): array => $l->definition)
                    ->all(),
            ])->all();

        $optionLists = OptionList::query()->with('items')->get()
            ->keyBy('key')
            ->map(fn (OptionList $ol): array => [
                'key' => $ol->key,
                'label' => $ol->label,
                'items' => $ol->items
                    ->map(fn (OptionItem $i): array => ['value' => $i->value, 'label' => $i->label])
                    ->values()->all(),
            ])->all();

        return [
            'version' => $this->version(),
            'modules' => $modules,
            'option_lists' => $optionLists,
        ];
    }
}
