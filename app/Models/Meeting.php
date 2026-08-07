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
 * @property string|null $location
 * @property Carbon $date_start
 * @property Carbon|null $date_end
 * @property int|null $duration_minutes
 * @property string $status
 * @property string|null $description
 */
class Meeting extends Model
{
    use HasUuids;
    use SoftDeletes;
    use Subjectable;

    /** @var list<string> */
    protected $fillable = [
        'subject_type', 'subject_id', 'assigned_user_id', 'created_by',
        'name', 'location', 'date_start', 'date_end', 'duration_minutes', 'status', 'description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date_start' => 'datetime', 'date_end' => 'datetime', 'duration_minutes' => 'integer'];
    }
}
