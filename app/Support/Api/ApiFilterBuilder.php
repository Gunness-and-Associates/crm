<?php

namespace App\Support\Api;

use App\Exceptions\Api\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Filter/sort/free-text grammar from docs/contracts/api-contract.md §1.2, whitelisted
 * against the module's own filterable/sortable field flags — never a hardcoded list.
 * An unknown filter or sort field is a 422 naming the field, never silently ignored.
 */
final class ApiFilterBuilder
{
    /**
     * The columns free-text `q=` searches — every one of the 7 shipped modules has
     * all three (Contactable's base, or Assessment's own equivalent columns). A
     * future module without them is a real gap to revisit, not a silent no-op:
     * scopeSearch below only touches columns the model's table actually has.
     */
    private const IDENTITY_FIELDS = ['first_name', 'last_name', 'primary_email'];

    public function __construct(private readonly ApiModuleRegistry $registry) {}

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function apply(Builder $query, string $moduleKey, Request $request): Builder
    {
        $this->applyFilters($query, $moduleKey, $request);
        $this->applySearch($query, $request);
        $this->applySort($query, $moduleKey, $request);

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyFilters(Builder $query, string $moduleKey, Request $request): void
    {
        $filters = $request->query('filter', []);
        if (! is_array($filters) || $filters === []) {
            return;
        }

        $filterable = $this->registry->filterableFields($moduleKey);
        $fields = $this->registry->fields($moduleKey);

        foreach ($filters as $field => $spec) {
            if (! is_string($field) || ! in_array($field, $filterable, true)) {
                $name = is_string($field) ? $field : (string) $field;

                throw new ApiException(422, 'validation_failed', 'One or more filters are invalid.', [
                    $name => ["The field [{$name}] is not filterable."],
                ]);
            }

            $type = $fields[$field]['type'] ?? null;
            $type = is_string($type) ? $type : 'text';

            if (is_array($spec)) {
                foreach ($spec as $operator => $value) {
                    $this->applyOperator($query, $field, is_string($operator) ? $operator : 'eq', $value, $type);
                }
            } else {
                $this->applyOperator($query, $field, 'eq', $spec, $type);
            }
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyOperator(Builder $query, string $field, string $operator, mixed $value, string $type): void
    {
        match ($operator) {
            'eq' => $query->where($field, $this->castValue($value, $type)),
            'neq' => $query->where($field, '!=', $this->castValue($value, $type)),
            'gt' => $query->where($field, '>', $this->castValue($value, $type)),
            'gte' => $query->where($field, '>=', $this->castValue($value, $type)),
            'lt' => $query->where($field, '<', $this->castValue($value, $type)),
            'lte' => $query->where($field, '<=', $this->castValue($value, $type)),
            'in' => $query->whereIn($field, array_map(
                fn (string $v): mixed => $this->castValue($v, $type),
                explode(',', $this->stringValue($value)),
            )),
            'like' => $query->where($field, 'like', $this->stringValue($value)),
            'null' => $this->stringValue($value) === 'true' ? $query->whereNull($field) : $query->whereNotNull($field),
            default => throw new ApiException(422, 'validation_failed', 'One or more filters are invalid.', [
                $field => ["Unknown filter operator [{$operator}]."],
            ]),
        };
    }

    private function castValue(mixed $value, string $type): mixed
    {
        $stringValue = $this->stringValue($value);

        return match ($type) {
            'int' => (int) $stringValue,
            'decimal', 'currency' => (float) $stringValue,
            'bool' => in_array(strtolower($stringValue), ['1', 'true'], true),
            'date', 'datetime' => ApiDate::in($stringValue),
            default => $stringValue,
        };
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $term = $request->query('q');
        if (! is_string($term) || $term === '') {
            return;
        }

        $table = $query->getModel()->getTable();
        $columns = array_values(array_filter(
            self::IDENTITY_FIELDS,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($columns === []) {
            return;
        }

        $query->where(function (Builder $query) use ($columns, $term): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', "%{$term}%");
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySort(Builder $query, string $moduleKey, Request $request): void
    {
        $sort = $request->query('sort');
        if (! is_string($sort) || $sort === '') {
            return;
        }

        $sortable = $this->registry->sortableFields($moduleKey);

        foreach (explode(',', $sort) as $part) {
            $descending = str_starts_with($part, '-');
            $field = $descending ? substr($part, 1) : $part;

            if (! in_array($field, $sortable, true)) {
                throw new ApiException(422, 'validation_failed', 'One or more sort fields are invalid.', [
                    $field => ["The field [{$field}] is not sortable."],
                ]);
            }

            $query->orderBy($field, $descending ? 'desc' : 'asc');
        }
    }
}
