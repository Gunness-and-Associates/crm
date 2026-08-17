<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The pipeline's own "fire events" step (api-contract.md Part 3) — dispatched
 * once a lead has been created or merged from an inbound ingest source.
 */
final class LeadIngested
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly string $source,
        public readonly ?string $matchedBy,
    ) {}
}
