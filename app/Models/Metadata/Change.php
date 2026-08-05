<?php

namespace App\Models\Metadata;

use App\Models\User;
use Database\Factories\Metadata\ChangeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $actor_id
 * @property string $kind
 * @property string|null $target_module
 * @property string|null $target_field
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property string|null $ddl
 * @property string|null $snapshot_path
 * @property string|null $reviewer_id
 * @property string|null $review_note
 * @property Carbon|null $applied_at
 */
class Change extends Model
{
    /** @use HasFactory<ChangeFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'actor_id', 'kind', 'target_module', 'target_field', 'payload', 'status',
        'ddl', 'snapshot_path', 'reviewer_id', 'review_note', 'applied_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'applied_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
