<?php

namespace App\Support;

/**
 * `full_name` is registered as an API-filterable/sortable/writable field on
 * every Contactable module, but has no real column or Eloquent accessor/mutator
 * behind it — `Contactable::fullName()` is a plain read-only method (can't share
 * its name with an `Attribute::make()` cast, which Eloquent requires to be named
 * identically), so it's unreachable via `getAttribute()`/mass assignment. Every
 * API write boundary splits an incoming `full_name` into the real first_name/
 * last_name columns using this, and every read calls `fullName()` directly
 * instead of `getAttribute('full_name')`.
 */
final class FullName
{
    /**
     * @return array{0: string, 1: string} [first, last]
     */
    public static function split(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value), 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
