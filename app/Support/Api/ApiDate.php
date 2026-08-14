<?php

namespace App\Support\Api;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon as IlluminateCarbon;

/**
 * The UTC datetime boundary (BACKEND_BRIEF rule 6 / docs/contracts/api-contract.md
 * §1.2) — every datetime crossing the API wire goes through here, in both
 * directions. Parsing is strict and format-explicit on purpose: never a locale- or
 * default-timezone-sensitive guess ("independent of the caller's locale" per Z-5.1) —
 * that ambiguity was the original silent-data-loss bug this rule exists to prevent.
 */
final class ApiDate
{
    public const WIRE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Outbound: always `Y-m-d H:i:s`, always UTC, no offset, no `T`, no `Z`.
     */
    public static function out(?IlluminateCarbon $date): ?string
    {
        return $date?->clone()->utc()->format(self::WIRE_FORMAT);
    }

    /**
     * Legacy V8 adapter inbound (api-contract.md §2.2 rule 3): accepts *only*
     * `Y-m-d H:i:s` — no ISO-8601, no bare date. "Reject anything else with a
     * clear error rather than silently discarding it — the silent discard was
     * the original bug."
     *
     * @throws \InvalidArgumentException
     */
    public static function inStrict(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $strict = Carbon::createFromFormat(self::WIRE_FORMAT, $value, 'UTC');
            if ($strict !== null && $strict->format(self::WIRE_FORMAT) === $value) {
                return $strict->utc();
            }
        } catch (InvalidFormatException) {
            // falls through to the exception below
        }

        throw new \InvalidArgumentException("Datetime [{$value}] is not in Y-m-d H:i:s format.");
    }

    /**
     * Inbound: accepts exactly `Y-m-d H:i:s` or full ISO-8601 — both unambiguous
     * regardless of locale. Anything else throws; the caller turns that into a 422
     * naming the field, never a silently blanked value.
     *
     * @throws \InvalidArgumentException
     */
    public static function in(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $strict = Carbon::createFromFormat(self::WIRE_FORMAT, $value, 'UTC');
            if ($strict !== null && $strict->format(self::WIRE_FORMAT) === $value) {
                return $strict->utc();
            }
        } catch (InvalidFormatException) {
            // fall through to the next attempt
        }

        // A bare date is unambiguous (no locale-dependent day/month ordering) and is
        // what filter[field][gte]=2026-07-01-style range filters commonly send.
        try {
            $dateOnly = Carbon::createFromFormat('Y-m-d', $value, 'UTC');
            if ($dateOnly !== null && $dateOnly->format('Y-m-d') === $value) {
                return $dateOnly->startOfDay()->utc();
            }
        } catch (InvalidFormatException) {
            // fall through to the ISO-8601 attempt below
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([.,]\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value) === 1) {
            try {
                return Carbon::parse($value)->utc();
            } catch (InvalidFormatException) {
                // fall through to the exception below
            }
        }

        throw new \InvalidArgumentException("Datetime [{$value}] is not in Y-m-d H:i:s or ISO-8601 format.");
    }
}
