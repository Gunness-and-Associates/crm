<?php

namespace App\Support\Ingest;

use Illuminate\Database\Eloquent\Model;

final readonly class IngestResult
{
    public function __construct(
        public Model $record,
        public string $status,
        public ?string $matchedBy,
    ) {}
}
