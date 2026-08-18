<?php

namespace App\Support\Etl\Concerns;

/**
 * Small value-coercion helpers shared by every LegacyTransformer — SuiteCRM
 * columns are untyped strings at the PHP layer regardless of their declared SQL
 * type, so every transformer needs the same "is this really absent" and
 * "is this really a number" checks.
 */
trait NormalizesLegacyValues
{
    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $string = $this->stringValue($value);

        return $string === '' ? null : $string;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Several `_cstm` "boolean" columns are actually varchar, not tinyint — an
     * empty string or a literal "0"/"false"/"no" (case-insensitive) is false,
     * anything else non-empty is true.
     */
    private function legacyBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $string = strtolower($this->stringValue($value));

        return $string !== '' && ! in_array($string, ['0', 'false', 'no'], true);
    }
}
