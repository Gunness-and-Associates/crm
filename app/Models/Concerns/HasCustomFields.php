<?php

namespace App\Models\Concerns;

use App\Support\FieldTypeContract;
use App\Support\MetadataRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the `{table}_custom` sidecar (mirrors SuiteCRM's `_cstm`) and derives
 * casts and fillable attributes from the `fields` metadata table, so any
 * Studio field works transparently on any Contactable model — no per-entity
 * code (BACKEND_BRIEF §2.1).
 *
 * Custom values live in $customFieldValues, NOT in Eloquent's own $attributes —
 * otherwise a plain save() would try to write them into the base table's
 * (nonexistent) column. They are cast via Eloquent's own castAttribute(), so
 * the field-type contract's cast strings behave identically to a real column.
 *
 * Field definitions come from MetadataRepository::compiled() (Z-4.4) rather
 * than a live Module/Field query — on a list of N records, retrieved() fires
 * once per row, and a live query there is a real N+1: the definitions are
 * identical for every row of the same table and change only on a Studio edit,
 * exactly what the compiled cache already tracks via its version bump.
 *
 * @mixin Model
 */
trait HasCustomFields
{
    /** @var array<string, mixed> */
    protected array $customFieldValues = [];

    /** @var list<string>|null */
    protected ?array $customFieldNamesCache = null;

    protected static function bootHasCustomFields(): void
    {
        static::retrieved(function (self $model): void {
            $model->mergeCustomAttributesFromSidecar();
        });

        static::saved(function (self $model): void {
            $model->persistCustomAttributesToSidecar();
        });
    }

    public function customTable(): string
    {
        return $this->getTable().'_custom';
    }

    public function getAttribute($key)
    {
        if (in_array($key, $this->customFieldNames(), true)) {
            return $this->customFieldValues[$key] ?? null;
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->customFieldNames(), true)) {
            $this->customFieldValues[$key] = $this->castAttribute($key, $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @return list<string>
     */
    public function getFillable(): array
    {
        return array_values(array_unique([...parent::getFillable(), ...$this->customFieldNames()]));
    }

    /**
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        $casts = [];
        foreach (array_merge(parent::getCasts(), $this->customFieldCasts()) as $key => $cast) {
            if (is_string($key) && is_string($cast)) {
                $casts[$key] = $cast;
            }
        }

        return $casts;
    }

    /**
     * @return array<string, string> field name => field-type key
     */
    protected function customFieldDefinitions(): array
    {
        return app(MetadataRepository::class)->customFieldDefinitionsForTable($this->getTable());
    }

    /**
     * @return list<string>
     */
    protected function customFieldNames(): array
    {
        if ($this->customFieldNamesCache === null) {
            $this->customFieldNamesCache = array_keys($this->customFieldDefinitions());
        }

        return $this->customFieldNamesCache;
    }

    /**
     * @return array<string, string>
     */
    protected function customFieldCasts(): array
    {
        $contract = app(FieldTypeContract::class);
        $casts = [];

        foreach ($this->customFieldDefinitions() as $name => $type) {
            if ($contract->exists($type)) {
                $cast = $contract->type($type)['cast'] ?? null;
                if (is_string($cast)) {
                    $casts[$name] = $cast;
                }
            }
        }

        return $casts;
    }

    protected function mergeCustomAttributesFromSidecar(): void
    {
        $this->customFieldNamesCache = null; // module/fields may have changed since last load
        $names = $this->customFieldNames();

        if ($this->getKey() === null || $names === [] || ! Schema::hasTable($this->customTable())) {
            return;
        }

        $row = DB::table($this->customTable())->where('id_c', $this->getKey())->first();
        if ($row === null) {
            return;
        }

        foreach ((array) $row as $column => $value) {
            if ($column !== 'id_c' && in_array($column, $names, true)) {
                $this->customFieldValues[$column] = $this->castAttribute($column, $value);
            }
        }
    }

    protected function persistCustomAttributesToSidecar(): void
    {
        if ($this->customFieldValues === [] || ! Schema::hasTable($this->customTable())) {
            return;
        }

        DB::table($this->customTable())->updateOrInsert(['id_c' => $this->getKey()], $this->customFieldValues);
    }
}
