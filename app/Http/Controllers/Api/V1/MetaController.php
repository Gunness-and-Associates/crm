<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Support\Api\ApiModuleRegistry;
use App\Support\Api\ApiResponse;
use App\Support\MetadataRepository;
use Illuminate\Http\JsonResponse;

/**
 * docs/contracts/api-contract.md §1.4 — makes the API self-describing, so an
 * integration written today keeps working after an administrator adds a field in
 * Studio without redeploying anything.
 */
final class MetaController extends Controller
{
    public function __construct(
        private readonly MetadataRepository $repository,
        private readonly ApiModuleRegistry $registry,
    ) {}

    public function modules(): JsonResponse
    {
        $modules = $this->repository->compiled()['modules'] ?? [];
        $data = [];

        foreach (is_array($modules) ? $modules : [] as $key => $module) {
            if (! is_string($key) || ! is_array($module) || ! ($module['enabled'] ?? true)) {
                continue;
            }

            $data[] = [
                'key' => $key,
                'label' => $module['label'] ?? $key,
                'base_type' => $module['base_type'] ?? null,
                'count' => $this->registry->exists($key) ? $this->registry->modelFor($key)::query()->count() : null,
            ];
        }

        return ApiResponse::collection($data);
    }

    public function fields(string $module): JsonResponse
    {
        $fields = $this->registry->fields($module);
        if ($fields === [] && ! $this->registry->exists($module)) {
            throw ApiException::notFound("Module [{$module}] does not exist.");
        }

        $data = [];
        foreach ($fields as $name => $field) {
            $optionListId = $field['option_list_id'] ?? null;

            $data[] = [
                'name' => $name,
                'label_key' => $field['label_key'] ?? null,
                'type' => $field['type'] ?? null,
                'required' => (bool) ($field['required'] ?? false),
                'filterable' => (bool) ($field['filterable'] ?? false),
                'sortable' => (bool) ($field['sortable'] ?? false),
                'options' => is_string($optionListId) ? $this->optionItems($optionListId) : null,
            ];
        }

        return ApiResponse::collection($data);
    }

    public function optionList(string $key): JsonResponse
    {
        $lists = $this->repository->compiled()['option_lists'] ?? [];
        $list = is_array($lists) ? ($lists[$key] ?? null) : null;

        if (! is_array($list)) {
            throw ApiException::notFound("Option list [{$key}] does not exist.");
        }

        $items = $list['items'] ?? [];

        return ApiResponse::collection($this->normalizeItems(is_array($items) ? $items : []));
    }

    /**
     * @param  array<mixed, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $normalized = [];
                foreach ($item as $key => $value) {
                    if (is_string($key)) {
                        $normalized[$key] = $value;
                    }
                }
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function optionItems(string $optionListId): array
    {
        $list = OptionList::query()->with('items')->find($optionListId);
        if ($list === null) {
            return [];
        }

        return array_values($list->items->map(fn (OptionItem $item): array => [
            'value' => $item->value,
            'label' => $item->label,
        ])->all());
    }
}
