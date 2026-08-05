<?php

namespace App\Support;

use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use Illuminate\Support\Facades\Cache;

/**
 * Compiles the metadata registry into one cached structure, keyed by a version
 * that bumps on any change. One cache read per request drives the whole engine
 * (schema, UI, permissions, API).
 */
final class MetadataRepository
{
    private const VERSION_KEY = 'meta:version';

    public function version(): int
    {
        $value = Cache::get(Keys::cache(self::VERSION_KEY), 1);

        return is_numeric($value) ? (int) $value : 1;
    }

    public function bump(): int
    {
        $next = $this->version() + 1;
        Cache::forever(Keys::cache(self::VERSION_KEY), $next);

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    public function compiled(): array
    {
        return Cache::rememberForever(
            Keys::cache('meta:v'.$this->version()),
            fn (): array => $this->compile(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compile(): array
    {
        $modules = Module::query()->with(['fields', 'layouts'])->get()
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
