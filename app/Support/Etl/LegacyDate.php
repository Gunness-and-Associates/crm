<?php

namespace App\Support\Etl;

use Illuminate\Support\Carbon;

/**
 * BACKEND_BRIEF §13: "the source stores UTC as `Y-m-d H:i:s`; carry across
 * unchanged, and reject anything unparseable into an error report rather than
 * writing null silently." A legacy MySQL zero-date (`0000-00-00 00:00:00`, the
 * classic "no value" artifact in an old, non-strict-mode install) is genuinely
 * absent data, not a malformed value — treated as null, not an error.
 */
final class LegacyDate
{
    /**
     * @throws \InvalidArgumentException if $value is non-empty but not
     *                                   Y-m-d H:i:s and not the zero-date
     */
    public static function parse(?string $value): ?Carbon
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $parsed = Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC');
        if ($parsed === null || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new \InvalidArgumentException("Legacy datetime [{$value}] is not Y-m-d H:i:s.");
        }

        return $parsed;
    }

    /**
     * For a source column typed `date` rather than `datetime` (e.g.
     * date_reviewed) — same zero-date/error handling, `Y-m-d` instead.
     *
     * @throws \InvalidArgumentException if $value is non-empty but not Y-m-d
     *                                   and not the zero-date
     */
    public static function parseDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $parsed = Carbon::createFromFormat('Y-m-d', $value, 'UTC');
        if ($parsed === null || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Legacy date [{$value}] is not Y-m-d.");
        }

        return $parsed;
    }
}
