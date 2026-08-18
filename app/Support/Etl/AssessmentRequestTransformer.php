<?php

namespace App\Support\Etl;

use App\Models\Assessment;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use App\Support\Etl\Concerns\RecoversLegacyEmail;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load order position after Leads (BACKEND_BRIEF §13) — source
 * `ga_assessment_request` (484 rows). Assessment is not Contactable (it is
 * not one of the §7.1 modules) — only a handful of columns exist on the
 * target, the rest of the ~22 extra source fields go into `scores`.
 *
 * `status`/`case_type` on the target are controlled-vocabulary workflow
 * fields (requested|in_review|completed|sent, crs|fsw|combined) — the raw
 * legacy `status` text doesn't belong there, so it stays out of the real
 * column (left at its schema default) and is kept as `legacy_status` inside
 * `scores` for traceability instead.
 */
final class AssessmentRequestTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;
    use RecoversLegacyEmail;

    private const SCORE_COLUMNS = [
        'worth_money', 'working_on_field_of_study', 'work_experience_year', 'whycanada',
        'status_details', 'source_details', 'source', 'refused_to_come_to_canada', 'referred_by',
        'other_interested', 'opportuinity_amount', 'interested_program', 'interested_course_study',
        'have_relative_canda', 'gender', 'ever_visited_canada', 'english_language_level',
        'employment_status', 'campaign_id_c', 'age',
    ];

    public function key(): string
    {
        return 'assessments_request';
    }

    public function modelClass(): string
    {
        return Assessment::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')->table('ga_assessment_request');

        if ($fromId !== null) {
            $query->where('id', '>=', $fromId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public function transform(array $row): ?array
    {
        $id = $this->stringValue($row['id'] ?? null);
        if ($id === '') {
            return null;
        }

        $deletedAt = (bool) ($row['deleted'] ?? false)
            ? LegacyDate::parse($this->nullableString($row['date_modified'] ?? null))
            : null;

        $scores = [];
        foreach (self::SCORE_COLUMNS as $column) {
            $value = $this->nullableString($row[$column] ?? null);
            if ($value !== null) {
                $scores[$column] = $value;
            }
        }
        $legacyStatus = $this->nullableString($row['status'] ?? null);
        if ($legacyStatus !== null) {
            $scores['legacy_status'] = $legacyStatus;
        }

        return [
            'id' => $id,
            'deleted_at' => $deletedAt,
            'first_name' => $this->nullableString($row['first_name'] ?? null),
            'last_name' => $this->nullableString($row['last_name'] ?? null),
            'primary_email' => $this->recoverEmail($id, 'GA_Assessment_Request'),
            'phone_mobile' => $this->nullableString($row['phone_mobile'] ?? null),
            'marital_status' => $this->nullableString($row['marital_status'] ?? null),
            'education_level' => $this->nullableString($row['highest_level_education'] ?? null),
            'assigned_user_id' => $this->nullableUuid($row['assigned_user_id'] ?? null),
            'scores' => $scores,
        ];
    }
}
