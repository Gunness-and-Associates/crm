<?php

namespace App\Support\SchemaManager;

final class ChangeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $changeId = null,
        public readonly ?string $snapshotPath = null,
        public readonly ?string $error = null,
    ) {}
}
