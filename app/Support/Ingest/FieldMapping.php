<?php

namespace App\Support\Ingest;

/**
 * One source-field -> CRM-field mapping rule (S-5.2's field-mapping editor shape:
 * "source field -> CRM field, transform, default"). `transform` is an optional
 * lightweight value normaliser applied during the *map* step — separate from the
 * pipeline's own universal *canonicalise* step, which handles enum/multienum
 * target fields by type, regardless of any per-mapping transform.
 */
final readonly class FieldMapping
{
    public function __construct(
        public string $sourceField,
        public string $targetField,
        public ?string $transform = null,
        public mixed $default = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceField: is_string($data['source_field'] ?? null) ? $data['source_field'] : '',
            targetField: is_string($data['target_field'] ?? null) ? $data['target_field'] : '',
            transform: is_string($data['transform'] ?? null) ? $data['transform'] : null,
            default: $data['default'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_field' => $this->sourceField,
            'target_field' => $this->targetField,
            'transform' => $this->transform,
            'default' => $this->default,
        ];
    }
}
