<?php

namespace App\Http\Controllers\Api\Legacy;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiFilterBuilder;
use App\Support\Api\ApiModuleRegistry;
use App\Support\Api\ApiResponse;
use App\Support\Api\ApiValidationRuleBuilder;
use App\Support\LegacyApi\LegacyFieldAliasResolver;
use App\Support\LegacyApi\LegacyModuleAlias;
use App\Support\LegacyApi\LegacyModuleTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The legacy `/Api/V8/*` adapter (docs/contracts/api-contract.md Part 2) — a thin
 * translation layer over the exact same services ModuleResourceController (v1)
 * uses, so the 133 existing n8n workflows keep running by changing only their base
 * URL. No business logic is duplicated here: ACL, filtering, and validation all go
 * through the same ApiFilterBuilder/ApiValidationRuleBuilder/HasAcl scope/
 * {Module}Policy as v1 — this class only translates shapes at the boundary
 * (module/field name aliasing, fixed Lead verticals, and the §2.2 wire quirks).
 *
 * Delete this whole namespace (plus config/legacy_api.php and its routes) once the
 * n8n workflows have migrated to `/api/v1`.
 */
final class V8ModuleController extends Controller
{
    use AuthorizesRequests;

    private const MAX_PAGE_SIZE = 1000;

    private const DEFAULT_PAGE_SIZE = 25;

    public function __construct(
        private readonly ApiModuleRegistry $registry,
        private readonly ApiFilterBuilder $filters,
        private readonly ApiValidationRuleBuilder $validationRules,
        private readonly LegacyModuleAlias $moduleAlias,
        private readonly LegacyFieldAliasResolver $fieldAlias,
    ) {}

    public function index(Request $request, string $legacyModule): JsonResponse
    {
        $target = $this->resolveTarget($legacyModule);
        $canonicalFields = $this->registry->fields($target->module);
        $translated = $this->translateQuery($request, $legacyModule, $target, $canonicalFields);

        $query = $this->registry->modelFor($target->module)::query();
        $this->filters->apply($query, $target->module, $translated, strict: false);
        $this->applyFixedVertical($query, $target);

        $pageParam = $this->stringKeyedArray($translated->query('page', []));

        $pageSize = $this->pageSize($pageParam);
        $page = is_numeric($pageParam['number'] ?? null) ? max(1, (int) $pageParam['number']) : 1;

        $total = (clone $query)->count();
        $records = $query->forPage($page, $pageSize)->get();
        $requestedFields = $this->requestedFields($translated, $target);

        $data = array_values($records->map(
            fn (Model $record): array => $this->toResourceObject($record, $legacyModule, $target, $canonicalFields, $requestedFields)
        )->all());

        return ApiResponse::collection($data, [
            'total-pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
        ]);
    }

    public function show(Request $request, string $legacyModule, string $id): JsonResponse
    {
        $target = $this->resolveTarget($legacyModule);
        $canonicalFields = $this->registry->fields($target->module);
        $translated = $this->translateQuery($request, $legacyModule, $target, $canonicalFields);

        $record = $this->find($target, $id);
        if ($record === null) {
            throw ApiException::notFound();
        }

        $requestedFields = $this->requestedFields($translated, $target);

        return ApiResponse::resource($this->toResourceObject($record, $legacyModule, $target, $canonicalFields, $requestedFields));
    }

    public function store(Request $request): JsonResponse
    {
        $legacyModule = $this->stringInput($request, 'data.type');
        $target = $this->resolveTarget($legacyModule);
        $modelClass = $this->registry->modelFor($target->module);
        $this->authorize('create', $modelClass);

        $canonicalFields = $this->registry->fields($target->module);
        [$attributes, $verticalAttributes] = $this->translateAttributes(
            $this->arrayInput($request, 'data.attributes'),
            $canonicalFields,
        );

        $validated = $this->validate($attributes, $canonicalFields, forCreate: true);
        $record = $modelClass::create($this->mergeWrite($validated, $verticalAttributes, $target));

        return ApiResponse::resource(
            $this->toResourceObject($record, $legacyModule, $target, $canonicalFields),
            201,
        );
    }

    public function update(Request $request): JsonResponse
    {
        $legacyModule = $this->stringInput($request, 'data.type');
        $id = $this->stringInput($request, 'data.id');
        $target = $this->resolveTarget($legacyModule);

        $record = $this->find($target, $id);
        if ($record === null) {
            throw ApiException::notFound();
        }
        $this->authorize('update', $record);

        $canonicalFields = $this->registry->fields($target->module);
        [$attributes, $verticalAttributes] = $this->translateAttributes(
            $this->arrayInput($request, 'data.attributes'),
            $canonicalFields,
        );

        $validated = $this->validate($attributes, $canonicalFields, forCreate: false);
        $record->update($this->mergeWrite($validated, $verticalAttributes, $target));

        return ApiResponse::resource(
            $this->toResourceObject($record->fresh() ?? $record, $legacyModule, $target, $canonicalFields),
        );
    }

    public function destroy(Request $request, string $legacyModule, string $id): Response
    {
        $target = $this->resolveTarget($legacyModule);

        $record = $this->find($target, $id);
        if ($record === null) {
            throw ApiException::notFound();
        }
        $this->authorize('delete', $record);

        $record->delete();

        return ApiResponse::noContent();
    }

    private function resolveTarget(string $legacyModule): LegacyModuleTarget
    {
        $target = $this->moduleAlias->resolve($legacyModule);

        if (! $this->registry->exists($target->module)) {
            throw ApiException::notFound("Legacy module [{$legacyModule}] is not yet available through this adapter.");
        }

        return $target;
    }

    private function find(LegacyModuleTarget $target, string $id): ?Model
    {
        $query = $this->registry->modelFor($target->module)::query();
        $this->applyFixedVertical($query, $target);

        return $query->find($id);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyFixedVertical(Builder $query, LegacyModuleTarget $target): void
    {
        if ($target->vertical !== null) {
            $query->where('vertical', $target->vertical);
        }
    }

    /**
     * Rewrites filter[]/sort/fields[] field names from legacy to canonical, so the
     * shared ApiFilterBuilder never needs to know a legacy request from a v1 one.
     *
     * @param  array<string, array<string, mixed>>  $canonicalFields
     */
    private function translateQuery(Request $request, string $legacyModule, LegacyModuleTarget $target, array $canonicalFields): Request
    {
        $query = $request->query();

        if (is_array($query['filter'] ?? null)) {
            $translated = [];
            foreach ($query['filter'] as $field => $spec) {
                if (! is_string($field)) {
                    continue;
                }
                $canonical = $this->fieldAlias->toCanonical($field, $canonicalFields);
                if (! $canonical->isVerticalAttribute) {
                    $translated[$canonical->key] = $spec;
                }
            }
            $query['filter'] = $translated;
        }

        if (is_string($query['sort'] ?? null)) {
            $query['sort'] = implode(',', array_map(function (string $part) use ($canonicalFields): string {
                $descending = str_starts_with($part, '-');
                $field = $descending ? substr($part, 1) : $part;
                $canonical = $this->fieldAlias->toCanonical($field, $canonicalFields)->key;

                return $descending ? "-{$canonical}" : $canonical;
            }, explode(',', $query['sort'])));
        }

        if (is_array($query['fields'] ?? null)) {
            $legacyList = $query['fields'][$legacyModule] ?? null;
            if (is_string($legacyList)) {
                unset($query['fields'][$legacyModule]);
                $query['fields'][$target->module] = implode(',', array_map(
                    fn (string $field): string => $this->fieldAlias->toCanonical(trim($field), $canonicalFields)->key,
                    explode(',', $legacyList),
                ));
            }
        }

        return $request->duplicate(query: $query);
    }

    /**
     * @param  array<string, mixed>  $attributes  legacy attribute name => value
     * @param  array<string, array<string, mixed>>  $canonicalFields
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function translateAttributes(array $attributes, array $canonicalFields): array
    {
        $top = [];
        $vertical = [];

        foreach ($attributes as $field => $value) {
            $canonical = $this->fieldAlias->toCanonical($field, $canonicalFields);
            if ($canonical->isVerticalAttribute) {
                $vertical[$canonical->key] = $value;
            } else {
                $top[$canonical->key] = $value;
            }
        }

        return [$top, $vertical];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $canonicalFields
     * @return array<string, mixed>
     */
    private function validate(array $attributes, array $canonicalFields, bool $forCreate): array
    {
        $rules = $this->validationRules->build($canonicalFields, forCreate: $forCreate, strictDates: true);

        return $this->stringKeyedArray(Validator::make($attributes, $rules)->validate());
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $verticalAttributes
     * @return array<string, mixed>
     */
    private function mergeWrite(array $validated, array $verticalAttributes, LegacyModuleTarget $target): array
    {
        if ($target->vertical !== null) {
            $validated['vertical'] = $target->vertical;
        }

        if ($verticalAttributes !== []) {
            $validated['vertical_attributes'] = $verticalAttributes;
        }

        return $validated;
    }

    /**
     * `fields[<legacyModule>]=a,b,c` (§2.1's own example) restricts the attributes
     * returned, same as v1's sparse fieldsets — translateQuery() already rewrote
     * this into `fields[<canonicalModule>]` with canonical field names.
     *
     * @return list<string>|null null means no restriction — return everything
     */
    private function requestedFields(Request $translated, LegacyModuleTarget $target): ?array
    {
        $fields = $translated->query('fields', []);
        $list = is_array($fields) ? ($fields[$target->module] ?? null) : null;

        if (! is_string($list)) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $list)), fn (string $f): bool => $f !== ''));
    }

    /**
     * @param  array<string, array<string, mixed>>  $canonicalFields
     * @param  list<string>|null  $requestedFields
     * @return array<string, mixed>
     */
    private function toResourceObject(Model $record, string $legacyModule, LegacyModuleTarget $target, array $canonicalFields, ?array $requestedFields = null): array
    {
        $knownNames = [...array_keys($canonicalFields), 'assigned_user_id', 'created_at', 'updated_at'];
        if ($requestedFields !== null) {
            $knownNames = array_values(array_intersect($knownNames, $requestedFields));
        }

        $attributes = [];
        foreach ($knownNames as $name) {
            $legacyName = $this->fieldAlias->toLegacyAttribute($name);
            $attributes[$legacyName] = $this->formatAttribute($record, $name, $canonicalFields);
        }

        $verticalAttributes = $record->getAttribute('vertical_attributes');
        if (is_array($verticalAttributes)) {
            foreach ($verticalAttributes as $key => $value) {
                if (is_string($key)) {
                    $attributes[$this->fieldAlias->toLegacyVerticalAttribute($key)] = $value;
                }
            }
        }

        $attributes['deleted'] = method_exists($record, 'trashed') && $record->trashed() ? '1' : '0';

        $key = $record->getKey();
        $id = is_string($key) || is_numeric($key) ? (string) $key : '';

        return ApiResponse::object($id, $legacyModule, $attributes);
    }

    /**
     * @param  array<string, array<string, mixed>>  $canonicalFields
     */
    private function formatAttribute(Model $record, string $name, array $canonicalFields): mixed
    {
        $value = $record->getAttribute($name);

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof Carbon) {
            return ApiDate::out($value);
        }

        $type = $canonicalFields[$name]['type'] ?? null;
        if ($type === 'bool' || is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $pageParam
     */
    private function pageSize(array $pageParam): int
    {
        $size = $pageParam['size'] ?? null;
        $size = is_numeric($size) ? (int) $size : self::DEFAULT_PAGE_SIZE;

        return max(1, min(self::MAX_PAGE_SIZE, $size));
    }

    private function stringInput(Request $request, string $key): string
    {
        $value = $request->input($key);
        if (! is_string($value) || $value === '') {
            throw ApiException::badRequest("[{$key}] is required.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayInput(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        return $this->stringKeyedArray(is_array($value) ? $value : []);
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
