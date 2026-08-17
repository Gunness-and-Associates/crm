<?php

namespace App\Support\Ingest\Sources;

/**
 * Flattens the four supported WordPress form plugins' webhook payloads into a
 * flat `field => value` array, *before* FieldMapRepository's per-source mapping
 * applies. Only the structure is normalised here — the actual field name -> CRM
 * field mapping (which varies per real-world form, even within one plugin) stays
 * in the settings-backed field map.
 *
 * These plugins have no single frozen/verified wire format the way api-
 * contract.md's legacy V8 shapes do (§2.1's payloads were captured from live n8n
 * traffic) — this is a best-effort structural normaliser for the commonly
 * documented shapes and should be revisited against a real payload from each
 * plugin once one is available:
 *  - Gravity Forms webhook add-on: `{"form_id": ..., "entry": {"1": "...", ...}}`
 *  - Contact Form 7: posts its own field names flat already (e.g. `your-name`)
 *  - WPForms / Elementor: `{"fields": {"1": {"name"|"id": ..., "value": ...}, ...}}`
 *    (as an object keyed by id, or a list of such objects)
 */
final class WordPressPayloadNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        if (is_array($payload['entry'] ?? null)) {
            return $this->stringKeyed($payload['entry']);
        }

        if (is_array($payload['fields'] ?? null)) {
            return $this->flattenFieldsArray($payload['fields']);
        }

        return $this->stringKeyed($payload);
    }

    /**
     * @param  array<mixed, mixed>  $fields
     * @return array<string, mixed>
     */
    private function flattenFieldsArray(array $fields): array
    {
        $flat = [];

        foreach ($fields as $key => $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = $field['name'] ?? $field['id'] ?? $key;
            $name = is_string($name) || is_numeric($name) ? (string) $name : null;

            if ($name !== null && array_key_exists('value', $field)) {
                $flat[$name] = $field['value'];
            }
        }

        return $flat;
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
