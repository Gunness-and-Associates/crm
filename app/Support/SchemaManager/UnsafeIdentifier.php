<?php

namespace App\Support\SchemaManager;

/**
 * Thrown by SchemaManager::ident() — the only path an identifier may take into a DDL
 * string. Fail loudly; never sanitise-and-continue (BACKEND_BRIEF §6.4).
 */
final class UnsafeIdentifier extends \RuntimeException
{
    public function __construct(string $raw)
    {
        parent::__construct("Unsafe identifier rejected: [{$raw}]");
    }
}
