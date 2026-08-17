<?php

namespace App\Support\Ingest;

/**
 * api-contract.md Part 3: phone numbers from inbound forms carry invisible
 * Unicode direction marks (RTL/LTR overrides, zero-width characters) — strip them,
 * then keep only `+` and digits.
 */
final class PhoneCleaner
{
    private const INVISIBLE_PATTERN = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    public static function clean(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $stripped = (string) preg_replace(self::INVISIBLE_PATTERN, '', $phone);
        $cleaned = (string) preg_replace('/[^+0-9]/', '', $stripped);

        return $cleaned === '' ? null : $cleaned;
    }
}
