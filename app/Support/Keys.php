<?php

namespace App\Support;

/**
 * Single choke point for cache and queue keys.
 *
 * Tenancy-ready rule 6: every cache and queue key goes through here, so a
 * per-tenant prefix can be injected in exactly one place at the Phase 8
 * multi-tenancy conversion. Until then {@see self::prefix()} is empty.
 */
final class Keys
{
    public static function cache(string $key): string
    {
        return self::prefix().$key;
    }

    public static function queue(string $name): string
    {
        return self::prefix().$name;
    }

    private static function prefix(): string
    {
        // Phase 8: return "tenant:{id}:" here. Single-tenant today → no prefix.
        return '';
    }
}
