<?php

namespace App\Models;

use App\Models\Concerns\Subjectable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $name
 * @property Carbon|null $due_date
 * @property string $status
 * @property string $priority
 * @property string|null $description
 */
class Task extends Model
{
    use HasUuids;
    use SoftDeletes;
    use Subjectable;

    /** @var list<string> */
    protected $fillable = [
        'subject_type', 'subject_id', 'assigned_user_id', 'created_by',
        'name', 'due_date', 'status', 'priority', 'description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }
}
