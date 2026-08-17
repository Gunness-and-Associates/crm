<?php

namespace App\Support\Ingest;

use Illuminate\Database\Eloquent\Model;

/**
 * An existing record the Deduplicator matched, and which rule matched it — "always
 * record which rule matched" (BACKEND_BRIEF §10).
 */
final readonly class DedupeMatch
{
    public function __construct(
        public Model $record,
        public string $matchedBy,
    ) {}
}
