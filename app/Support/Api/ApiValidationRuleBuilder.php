<?php

namespace App\Support\Api;

use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Support\FieldTypeContract;
use Illuminate\Validation\Rule;

/**
 * Builds Laravel validation rules for store/update straight from a module's compiled
 * field metadata — never a hand-written rule set per module (Z-5.2). Mirrors
 * SchemaManager::buildColumnDefinition()'s own placeholder-substitution approach, but
 * for validation rules instead of column DDL.
 *
 * Note: field-types.json's own `min::min`/`max::max` int bounds and `::allowed_mimes`
 * are not built here — the `fields` table has no columns to store those values in yet
 * (SchemaManager never persists them either), so there is nothing to substitute.
 */
final class ApiValidationRuleBuilder
{
    public function __construct(private readonly FieldTypeContract $contract) {}

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<int, mixed>>
     */
    public function build(array $fields, bool $forCreate): array
    {
        $rules = [];

        foreach ($fields as $name => $field) {
            [$rule, $wildcard] = $this->rulesFor($field, $forCreate);
            $rules[$name] = $rule;

            if ($wildcard !== null) {
                $rules["{$name}.*"] = $wildcard;
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{0: array<int, mixed>, 1: array<int, mixed>|null}
     */
    private function rulesFor(array $field, bool $forCreate): array
    {
        $type = is_string($field['type'] ?? null) ? $field['type'] : 'text';
        $required = $forCreate && (bool) ($field['required'] ?? false);
        $wildcard = null;

        if ($type === 'bool') {
            return [$forCreate ? ['boolean'] : ['sometimes', 'boolean'], null];
        }

        $rule = [$required ? 'required' : ($forCreate ? 'nullable' : 'sometimes')];

        switch ($type) {
            case 'text':
                $rule[] = 'string';
                $rule[] = 'max:'.($this->intOrNull($field['max_length'] ?? null) ?? $this->contract->lengthDefault($type));
                break;
            case 'textarea':
                $rule[] = 'string';
                $rule[] = 'max:65535';
                break;
            case 'enum':
                $rule[] = 'string';
                $values = $this->optionValues($field['option_list_id'] ?? null);
                if ($values !== []) {
                    $rule[] = Rule::in($values);
                }
                break;
            case 'multienum':
                $rule[] = 'array';
                $values = $this->optionValues($field['option_list_id'] ?? null);
                if ($values !== []) {
                    $wildcard = [Rule::in($values)];
                }
                break;
            case 'int':
                $rule[] = 'integer';
                break;
            case 'decimal':
                $rule[] = 'numeric';
                break;
            case 'currency':
                $rule[] = 'numeric';
                $rule[] = 'min:0';
                break;
            case 'date':
            case 'datetime':
                $rule[] = function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        ApiDate::in(is_string($value) ? $value : null);
                    } catch (\InvalidArgumentException) {
                        $fail("The {$attribute} must be Y-m-d H:i:s or full ISO-8601.");
                    }
                };
                break;
            case 'email':
                $rule[] = 'email:rfc';
                $rule[] = 'max:255';
                break;
            case 'phone':
                $rule[] = 'string';
                $rule[] = 'max:50';
                break;
            case 'url':
                $rule[] = 'url';
                $rule[] = 'max:500';
                break;
            case 'relate':
                $rule[] = 'uuid';
                $table = $this->relatedTable($field['related_module_id'] ?? null);
                if ($table !== null) {
                    $rule[] = Rule::exists($table, 'id');
                }
                break;
            default:
                $rule[] = 'string';
        }

        return [$rule, $wildcard];
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<string>
     */
    private function optionValues(mixed $optionListId): array
    {
        if (! is_string($optionListId)) {
            return [];
        }

        $list = OptionList::query()->with('items')->find($optionListId);
        if ($list === null) {
            return [];
        }

        return array_values($list->items->map(fn (OptionItem $item): string => $item->value)->all());
    }

    private function relatedTable(mixed $relatedModuleId): ?string
    {
        if (! is_string($relatedModuleId)) {
            return null;
        }

        $module = Module::query()->find($relatedModuleId);

        return $module?->table_name;
    }
}
