<?php

namespace App\Models;

use Database\Factories\IngestLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $source
 * @property array<string, mixed> $raw_payload
 * @property array<string, mixed>|null $mapped_attributes
 * @property string $status
 * @property string|null $record_id
 * @property string|null $matched_by
 * @property string|null $error
 */
class IngestLog extends Model
{
    /** @use HasFactory<IngestLogFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'source', 'raw_payload', 'mapped_attributes', 'status', 'record_id', 'matched_by', 'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['raw_payload' => 'array', 'mapped_attributes' => 'array'];
    }
}
