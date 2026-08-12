<?php

namespace App\Support;

/**
 * Reads the frozen field-type contract (resources/contracts/field-types.json).
 * SchemaManager builds DDL from it; never invent a type mapping elsewhere.
 */
final class FieldTypeContract
{
    /** @var array<int|string, mixed> */
    private readonly array $types;

    /** @var list<string> */
    private readonly array $reservedFieldNames;

    private readonly string $fieldNamePattern;

    /** @var array<int|string, mixed> */
    private readonly array $typeChangeMatrix;

    public function __construct()
    {
        $raw = json_decode((string) file_get_contents(resource_path('contracts/field-types.json')), true);
        $data = is_array($raw) ? $raw : [];

        $this->types = $this->arr($data['types'] ?? null);
        $this->reservedFieldNames = array_values(array_map(
            fn (mixed $v): string => $this->str($v),
            $this->arr($data['reserved_field_names'] ?? null),
        ));
        $this->fieldNamePattern = $this->str($data['field_name_pattern'] ?? null, '^[a-z][a-z0-9_]{1,58}$');
        $this->typeChangeMatrix = $this->arr($data['type_change_matrix'] ?? null);
    }

    public function exists(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function type(string $type): array
    {
        if (! isset($this->types[$type])) {
            throw new \InvalidArgumentException("Unknown field type [{$type}].");
        }

        return $this->arr($this->types[$type]);
    }

    /**
     * @return list<string>
     */
    public function reservedFieldNames(): array
    {
        return $this->reservedFieldNames;
    }

    public function fieldNamePattern(): string
    {
        return $this->fieldNamePattern;
    }

    public function column(string $type): string
    {
        return $this->str($this->type($type)['column'] ?? null, 'varchar(255)');
    }

    /**
     * @return list<string>
     */
    public function requiredOptions(string $type): array
    {
        return array_values(array_map(
            fn (mixed $v): string => $this->str($v),
            $this->arr($this->type($type)['requires'] ?? null),
        ));
    }

    public function filterable(string $type): bool
    {
        return (bool) ($this->type($type)['filterable'] ?? false);
    }

    public function sortable(string $type): bool
    {
        return (bool) ($this->type($type)['sortable'] ?? false);
    }

    public function indexable(string $type): bool
    {
        return (bool) ($this->type($type)['indexable'] ?? false);
    }

    public function lengthDefault(string $type): int
    {
        return $this->int($this->arr($this->type($type)['length'] ?? null)['default'] ?? null, 255);
    }

    public function lengthMin(string $type): int
    {
        return $this->int($this->arr($this->type($type)['length'] ?? null)['min'] ?? null, 1);
    }

    public function lengthMax(string $type): int
    {
        return $this->int($this->arr($this->type($type)['length'] ?? null)['max'] ?? null, 1000);
    }

    public function precisionDefault(string $type): int
    {
        return $this->int($this->arr($this->type($type)['precision'] ?? null)['default'] ?? null, 18);
    }

    public function precisionMax(string $type): int
    {
        return $this->int($this->arr($this->type($type)['precision'] ?? null)['max'] ?? null, 30);
    }

    public function scaleDefault(string $type): int
    {
        return $this->int($this->arr($this->type($type)['scale'] ?? null)['default'] ?? null, 2);
    }

    public function scaleMax(string $type): int
    {
        return $this->int($this->arr($this->type($type)['scale'] ?? null)['max'] ?? null, 8);
    }

    /**
     * Classify a type change as safe | requires_confirmation | blocked | unknown,
     * matching the change against the contract's free-text change matrix.
     */
    public function typeChangeClass(string $fromType, string $toType): string
    {
        if ($fromType === $toType) {
            return 'safe';
        }

        $description = "{$fromType} -> {$toType}";

        foreach (['blocked', 'requires_confirmation_and_snapshot', 'safe_widening'] as $bucket) {
            foreach ($this->arr($this->typeChangeMatrix[$bucket] ?? null) as $entry) {
                if (is_string($entry) && str_contains($entry, $description)) {
                    return match ($bucket) {
                        'blocked' => 'blocked',
                        'requires_confirmation_and_snapshot' => 'requires_confirmation',
                        default => 'safe',
                    };
                }
            }
        }

        return 'unknown';
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
