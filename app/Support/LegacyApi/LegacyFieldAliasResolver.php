<?php

namespace App\Support\LegacyApi;

/**
 * Bidirectional legacy <-> canonical field-name translation, api-contract.md §2.4.
 * Most fields (first_name, phone_mobile, status, ...) are identical between legacy
 * and canonical naming and pass straight through unchanged — only the explicitly
 * listed aliases, the `best_time_to_call_*_c` wildcard, and the generic "any other
 * `*_c`" fallback need real translation.
 */
final class LegacyFieldAliasResolver
{
    private const BEST_TIME_PATTERN = '/^best_time_to_call_(.+)_c$/';

    /**
     * Request-side: a legacy attribute name (filter/sort/sparse-field key, or a
     * write payload key) to where it lives canonically.
     *
     * @param  array<string, mixed>  $canonicalFields  the target module's own
     *                                                 registered fields (ApiModuleRegistry::fields())
     */
    public function toCanonical(string $legacyField, array $canonicalFields): LegacyFieldTarget
    {
        $fieldAliases = $this->stringMap('legacy_api.field_aliases');
        if (isset($fieldAliases[$legacyField])) {
            return LegacyFieldTarget::attribute($fieldAliases[$legacyField]);
        }

        $verticalAliases = $this->stringMap('legacy_api.vertical_attribute_aliases');
        if (isset($verticalAliases[$legacyField])) {
            return LegacyFieldTarget::verticalAttribute($verticalAliases[$legacyField]);
        }

        if (preg_match(self::BEST_TIME_PATTERN, $legacyField, $matches) === 1) {
            return LegacyFieldTarget::verticalAttribute("best_time_to_call_{$matches[1]}");
        }

        if (str_ends_with($legacyField, '_c')) {
            $stripped = substr($legacyField, 0, -2);

            return array_key_exists($stripped, $canonicalFields)
                ? LegacyFieldTarget::attribute($stripped)
                : LegacyFieldTarget::verticalAttribute($stripped);
        }

        return LegacyFieldTarget::attribute($legacyField);
    }

    /**
     * Response-side: a canonical top-level attribute name to its legacy name.
     */
    public function toLegacyAttribute(string $canonicalField): string
    {
        $reverse = array_flip($this->stringMap('legacy_api.field_aliases'));

        return $reverse[$canonicalField] ?? $canonicalField;
    }

    /**
     * Response-side: a `vertical_attributes` key to its legacy name.
     */
    public function toLegacyVerticalAttribute(string $key): string
    {
        $reverse = array_flip($this->stringMap('legacy_api.vertical_attribute_aliases'));

        // Explicit aliases and the best_time_to_call_* wildcard both reverse the
        // same way: the vertical_attributes key plus a restored `_c` suffix.
        return $reverse[$key] ?? "{$key}_c";
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(string $configKey): array
    {
        $config = config($configKey, []);
        $map = [];

        foreach (is_array($config) ? $config : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }
}
