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
     * A handful of legacy `created_by`/`modified_user_id`-style FK columns hold
     * free text ("Prince Saha", an email address) instead of a real user id —
     * a data-quality defect, not a real reference. Treat anything that isn't
     * UUID-shaped as absent rather than letting it hit the target's foreign
     * key constraint and fail the whole row.
     */
    private function nullableUuid(mixed $value): ?string
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            return null;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string) === 1
            ? $string
            : null;
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
