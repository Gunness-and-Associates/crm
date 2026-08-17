<?php

namespace App\Support\Ingest;

use App\Support\Settings;

/**
 * Per-source field mapping, stored in `settings` (Z-1.2's typed store — never
 * `.env`) so it stays editable from the interface (S-5.2's field-mapping editor)
 * without a deploy. Falls back to config/ingest.php's defaults until an admin has
 * configured a source through the UI.
 */
final class FieldMapRepository
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return list<FieldMapping>
     */
    public function forSource(string $source): array
    {
        $stored = $this->settings->get("ingest.{$source}.field_map");
        $rows = is_array($stored) ? $stored : $this->defaultFor($source);

        return array_values(array_map(
            fn (mixed $row): FieldMapping => FieldMapping::fromArray($this->stringKeyed($row)),
            $rows,
        ));
    }

    /**
     * @param  list<FieldMapping>  $mappings
     */
    public function setForSource(string $source, array $mappings): void
    {
        $this->settings->set(
            "ingest.{$source}.field_map",
            array_map(fn (FieldMapping $mapping): array => $mapping->toArray(), $mappings),
        );
    }

    /**
     * @return list<mixed>
     */
    private function defaultFor(string $source): array
    {
        $defaults = config('ingest.field_maps', []);
        $value = is_array($defaults) ? ($defaults[$source] ?? []) : [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $value): array
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
