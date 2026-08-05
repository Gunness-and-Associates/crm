<?php

namespace App\Support\SchemaManager;

/**
 * The crm:schema named lock could not be acquired within 10s. Concurrent DDL
 * is rejected, not queued (BACKEND_BRIEF §6.2 step 2).
 */
final class ConcurrentSchemaChange extends \RuntimeException {}
