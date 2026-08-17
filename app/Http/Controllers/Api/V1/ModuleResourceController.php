<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiFilterBuilder;
use App\Support\Api\ApiModuleRegistry;
use App\Support\Api\ApiResponse;
use App\Support\Api\ApiValidationRuleBuilder;
use App\Support\FullName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * One generic controller for every registered module (Z-5.2) — index/show/store/
 * update/destroy driven entirely by ApiModuleRegistry's metadata, never a
 * per-module subclass. docs/contracts/api-contract.md §1.2–1.3.
 *
 * ACL is two layers (Z-5.4), matching the interface exactly since both share the
 * same code:
 *  - view-level access is the model classes' own HasAcl global scope, applied for
 *    free by every `{module}::query()`/`findOrFail()` call below — this alone
 *    produces the 404-vs-403 rule (§1.5): a record the caller's role excludes is
 *    simply not found, the same as a record that never existed. No second filter
 *    is written here for that.
 *  - create/edit/delete are a *different* permission level than view (a role can
 *    be view=All but edit=Owner), which the query scope alone can't express — so
 *    store/update/destroy additionally check the matching {Module}Policy (extends
 *    CrmPolicy, the same policy Filament resources use), producing 403 for a
 *    visible-but-not-editable record.
 */
final class ModuleResourceController extends Controller
{
    use AuthorizesRequests;

    private const DEFAULT_PAGE_SIZE = 25;

    private const MAX_PAGE_SIZE = 200;

    public function __construct(
        private readonly ApiModuleRegistry $registry,
        private readonly ApiFilterBuilder $filters,
        private readonly ApiValidationRuleBuilder $validationRules,
    ) {}

    public function index(Request $request, string $module): JsonResponse
    {
        $modelClass = $this->resolveModel($module);
        $query = $modelClass::query();
        $this->filters->apply($query, $module, $request);

        if ($this->wantsAssignee($request)) {
            $query->with('assignedUser');
        }

        $pageSize = $this->pageSize($request);
        $page = $this->stringKeyedArray($request->query('page', []));

        if (array_key_exists('cursor', $page)) {
            $cursorValue = $page['cursor'];
            $cursor = is_string($cursorValue) && $cursorValue !== '' ? $cursorValue : null;

            return $this->cursorPage($query, $module, $request, $pageSize, $cursor);
        }

        return $this->offsetPage($query, $module, $request, $pageSize, $page);
    }

    public function show(Request $request, string $module, string $id): JsonResponse
    {
        $record = $this->findOrFail($module, $id, $request);

        $response = ApiResponse::resource($this->toResourceObject($record, $module, $request));
        $response->headers->set('ETag', $this->recordETag($record));

        return $response;
    }

    public function store(Request $request, string $module): JsonResponse
    {
        $modelClass = $this->resolveModel($module);
        $this->authorize('create', $modelClass);

        $fields = $this->registry->fields($module);
        $rules = $this->validationRules->build($fields, forCreate: true);

        $attributes = $this->splitFullName($this->normalizeDatetimes($this->stringKeyedArray($request->validate($rules)), $fields));

        /** @var Model $record */
        $record = $modelClass::create($attributes);

        return ApiResponse::created(
            $this->toResourceObject($record, $module, $request),
            "/api/v1/{$module}/{$this->keyString($record)}",
        );
    }

    public function update(Request $request, string $module, string $id): JsonResponse
    {
        $record = $this->findOrFail($module, $id, $request);
        $this->authorize('update', $record);

        $ifMatch = $request->header('If-Match');
        if ($ifMatch !== null && $ifMatch !== $this->recordETag($record)) {
            throw ApiException::conflict();
        }

        $fields = $this->registry->fields($module);
        $rules = $this->validationRules->build($fields, forCreate: false);
        $attributes = $this->splitFullName($this->normalizeDatetimes($this->stringKeyedArray($request->validate($rules)), $fields));

        $record->update($attributes);
        $record = $record->fresh() ?? $record;

        $response = ApiResponse::resource($this->toResourceObject($record, $module, $request));
        $response->headers->set('ETag', $this->recordETag($record));

        return $response;
    }

    public function destroy(Request $request, string $module, string $id): Response
    {
        $record = $this->findOrFail($module, $id, $request);
        $this->authorize('delete', $record);

        $record->delete();

        return ApiResponse::noContent();
    }

    /**
     * @return class-string<Model>
     */
    private function resolveModel(string $module): string
    {
        if (! $this->registry->exists($module)) {
            throw ApiException::notFound("Module [{$module}] does not exist.");
        }

        return $this->registry->modelFor($module);
    }

    private function findOrFail(string $module, string $id, Request $request): Model
    {
        $modelClass = $this->resolveModel($module);
        $query = $modelClass::query();

        if ($this->wantsAssignee($request)) {
            $query->with('assignedUser');
        }

        $record = $query->find($id);
        if ($record === null) {
            throw ApiException::notFound();
        }

        return $record;
    }

    private function wantsAssignee(Request $request): bool
    {
        $include = $request->query('include');

        return is_string($include) && in_array('assignee', explode(',', $include), true);
    }

    private function pageSize(Request $request): int
    {
        $page = $request->query('page', []);
        $size = is_array($page) ? ($page['size'] ?? null) : null;

        $size = is_numeric($size) ? (int) $size : self::DEFAULT_PAGE_SIZE;

        return max(1, min(self::MAX_PAGE_SIZE, $size));
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $page
     */
    private function offsetPage(Builder $query, string $module, Request $request, int $pageSize, array $page): JsonResponse
    {
        $number = is_numeric($page['number'] ?? null) ? max(1, (int) $page['number']) : 1;

        $total = (clone $query)->count();
        $pages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;

        $records = $query->forPage($number, $pageSize)->get();
        $data = array_values($records->map(fn (Model $record): array => $this->toResourceObject($record, $module, $request))->all());

        $base = "/api/v1/{$module}";

        return ApiResponse::collection(
            $data,
            ['total' => $total, 'count' => count($data), 'page' => $number, 'pages' => $pages, 'per_page' => $pageSize],
            [
                'self' => "{$base}?page[number]={$number}",
                'next' => $number < $pages ? "{$base}?page[number]=".($number + 1) : null,
                'prev' => $number > 1 ? "{$base}?page[number]=".($number - 1) : null,
            ],
        );
    }

    /**
     * Keyset pagination on `id` — HasUuids generates ordered (timestamp-prefixed)
     * UUIDs, so ascending `id` order is stable insertion order. No `total`/`pages`:
     * that is the whole point of cursor paging on a large collection (BACKEND_BRIEF
     * §16 — no full-collection COUNT just to render a page).
     */
    /**
     * @param  Builder<Model>  $query
     */
    private function cursorPage(Builder $query, string $module, Request $request, int $pageSize, ?string $cursor): JsonResponse
    {
        $query->orderBy('id');

        if ($cursor !== null) {
            $decoded = base64_decode($cursor, true);
            if ($decoded !== false && $decoded !== '') {
                $query->where('id', '>', $decoded);
            }
        }

        $records = $query->limit($pageSize + 1)->get();
        $hasMore = $records->count() > $pageSize;
        $records = $records->take($pageSize);

        $data = array_values($records->map(fn (Model $record): array => $this->toResourceObject($record, $module, $request))->all());

        $base = "/api/v1/{$module}";
        $last = $records->last();
        $nextCursor = $hasMore && $last instanceof Model ? base64_encode($this->keyString($last)) : null;

        return ApiResponse::collection(
            $data,
            ['count' => count($data), 'per_page' => $pageSize],
            [
                'self' => $cursor === null ? $base : "{$base}?page[cursor]={$cursor}",
                'next' => $nextCursor === null ? null : "{$base}?page[cursor]={$nextCursor}",
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toResourceObject(Model $record, string $module, Request $request): array
    {
        $fieldTypes = $this->registry->fields($module);
        $knownNames = [...array_keys($fieldTypes), 'assigned_user_id', 'created_at', 'updated_at'];

        $requested = $this->sparseFields($module, $request) ?? $knownNames;

        $attributes = [];
        foreach ($requested as $name) {
            if (in_array($name, $knownNames, true)) {
                $attributes[$name] = $this->formatAttribute($record, $name, $fieldTypes);
            }
        }

        $relationships = [];
        if ($record->relationLoaded('assignedUser')) {
            $assignee = $record->getAttribute('assignedUser');
            if ($assignee instanceof Model) {
                $relationships['assignee'] = ['data' => ['id' => $this->keyString($assignee), 'type' => 'users']];
            }
        }

        $key = $this->keyString($record);

        return ApiResponse::object(
            $key,
            $module,
            $attributes,
            $relationships,
            ['self' => "/api/v1/{$module}/{$key}"],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldTypes
     */
    private function formatAttribute(Model $record, string $name, array $fieldTypes): mixed
    {
        // full_name has no real column or Eloquent accessor (app/Support/FullName.php)
        // — getAttribute() would just return null.
        if ($name === 'full_name' && method_exists($record, 'fullName')) {
            return $record->fullName();
        }

        $value = $record->getAttribute($name);

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (in_array($name, ['created_at', 'updated_at'], true) && $value instanceof Carbon) {
            return ApiDate::out($value);
        }

        $type = $fieldTypes[$name]['type'] ?? null;

        if (($type === 'date' || $type === 'datetime') && $value instanceof Carbon) {
            return ApiDate::out($value);
        }

        if ($type === 'bool') {
            return (bool) $value;
        }

        return $value;
    }

    /**
     * @return list<string>|null null means "no restriction, return everything"
     */
    private function sparseFields(string $module, Request $request): ?array
    {
        $fields = $request->query('fields', []);
        if (! is_array($fields) || ! is_string($fields[$module] ?? null)) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $fields[$module])), fn (string $f): bool => $f !== ''));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function normalizeDatetimes(array $attributes, array $fields): array
    {
        foreach ($attributes as $name => $value) {
            $type = $fields[$name]['type'] ?? null;
            if (($type === 'date' || $type === 'datetime') && is_string($value)) {
                $attributes[$name] = ApiDate::in($value);
            }
        }

        return $attributes;
    }

    /**
     * full_name has no real column to write to (app/Support/FullName.php) — an
     * incoming value is split into the real first_name/last_name columns instead.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function splitFullName(array $attributes): array
    {
        $value = $attributes['full_name'] ?? null;
        if (! is_string($value)) {
            return $attributes;
        }

        unset($attributes['full_name']);
        [$attributes['first_name'], $attributes['last_name']] = FullName::split($value);

        return $attributes;
    }

    private function recordETag(Model $record): string
    {
        $updatedAt = $record->getAttribute('updated_at');
        $stamp = $updatedAt instanceof Carbon ? $updatedAt->toIso8601String() : '0';

        return '"'.md5($this->keyString($record).$stamp).'"';
    }

    private function keyString(Model $record): string
    {
        $key = $record->getKey();

        return is_string($key) || is_numeric($key) ? (string) $key : spl_object_hash($record);
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
