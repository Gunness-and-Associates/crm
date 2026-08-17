<?php

namespace App\Support\Etl;

final class MigrationResult
{
    public int $total = 0;

    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var list<array{id: string, message: string}> */
    public array $errors = [];

    public function __construct(public readonly string $key) {}

    public function recordError(string $id, string $message): void
    {
        $this->errors[] = ['id' => $id, 'message' => $message];
    }
}
