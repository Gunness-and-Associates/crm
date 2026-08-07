<?php

namespace App\Models;

use App\Models\Concerns\HasActivities;
use App\Models\Concerns\HasCustomFields;
use App\Support\Acl\Aclable;
use App\Support\Acl\HasAcl;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Express Entry CRS/FSW calculator (source ga_assessment_request +
 * ga_assessment_score, ~88 scoring fields — DATA_MODEL §2).
 *
 * @property string $id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $primary_email
 * @property string|null $phone_mobile
 * @property string $case_type
 * @property string $status
 * @property int|null $crs_score
 * @property int|null $fsw_score
 * @property string|null $marital_status
 * @property string|null $education_level
 * @property string|null $language_test_type
 * @property int|null $clb_speaking
 * @property int|null $clb_listening
 * @property int|null $clb_reading
 * @property int|null $clb_writing
 * @property array<string, mixed>|null $scores
 * @property string|null $lead_id
 * @property string|null $assessed_by
 * @property string|null $assigned_user_id
 * @property Carbon|null $assessed_at
 */
class Assessment extends Model implements Aclable
{
    use HasAcl;
    use HasActivities;
    use HasCustomFields;

    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'first_name', 'last_name', 'primary_email', 'phone_mobile',
        'case_type', 'status', 'crs_score', 'fsw_score', 'marital_status', 'education_level',
        'language_test_type', 'clb_speaking', 'clb_listening', 'clb_reading', 'clb_writing', 'scores',
        'lead_id', 'assessed_by', 'assigned_user_id', 'assessed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'crs_score' => 'integer',
            'fsw_score' => 'integer',
            'clb_speaking' => 'integer',
            'clb_listening' => 'integer',
            'clb_reading' => 'integer',
            'clb_writing' => 'integer',
            'scores' => 'array',
            'assessed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
