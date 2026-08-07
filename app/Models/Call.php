<?php

namespace App\Models;

use App\Models\Concerns\Subjectable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Read-mostly log — no telephony integration. Created manually or by an
 * external system via the API when calling happens outside the CRM.
 *
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $direction
 * @property Carbon $date_start
 * @property int|null $duration_minutes
 * @property string|null $outcome
 * @property string|null $summary
 * @property string|null $recording_url
 */
class Call extends Model
{
    use HasUuids;
    use SoftDeletes;
    use Subjectable;

    /** @var list<string> */
    protected $fillable = [
        'subject_type', 'subject_id', 'assigned_user_id', 'created_by',
        'direction', 'date_start', 'duration_minutes', 'outcome', 'summary', 'recording_url',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date_start' => 'datetime', 'duration_minutes' => 'integer'];
    }
}
