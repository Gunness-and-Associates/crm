<?php

namespace App\Support\Etl;

use App\Models\Assessment;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use App\Support\Etl\Concerns\RecoversLegacyEmail;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load order position after Leads (BACKEND_BRIEF §13) — source
 * `ga_assessment_score` (8,147 rows), the CRS/FSW factor breakdown. `case_type`
 * is derived from which score is present (real signal, unlike the request
 * table) rather than left at the schema default.
 */
final class AssessmentScoreTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;
    use RecoversLegacyEmail;

    // Excludes crs_score/fsw_score/speaking/reading/writing/listening/education
    // (mapped to real typed columns below) and case_type (derived, not raw).
    private const SCORE_COLUMNS = [
        'household_income', 'country', 'spouse_experience_in_canada', 'spouse_lang_speaking',
        'spouse_education', 'language_test_latest', 'worth_money', 'job_offer_noc_teer',
        'work_experience_canada', 'fsw_work_experience_score', 'fsw_total_adaptiblity', 'fsw_spouse_lang',
        'fsw_spoouse_canadian_edu_ada', 'obtained_qualifi_certificate', 'fsw_relative_living',
        'spouse_studyanada', 'fsw_language_score', 'spouse_lang_test_writing', 'fsw_job_offer',
        'fsw_education_score', 'spouse_lang_test_reading', 'fswanadian_edu_adapt',
        'spouse_lang_test_listening', 'fsw_arranged_employment', 'spouseitizen', 'fsw_age_score',
        'foreign_work_experience', 'field_of_study', 'canadian_education_level',
        'relative_inanada', 'canadian_education', 'bring_spouse_toanada',
        'possess_a_nominationert', 'age', 'additional_test_taken', 'occupation',
        'additional_language_writing', 'additional_language_speaking', 'spouse_lan_test',
        'additional_language_listenin', 'legitimate_job_offer', 'additional_lang_test_reading',
    ];

    public function key(): string
    {
        return 'assessments_score';
    }

    public function modelClass(): string
    {
        return Assessment::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')->table('ga_assessment_score');

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

        $crsScore = $this->nullableInt($row['crs_score'] ?? null);
        $fswScore = $this->nullableInt($row['fsw_score'] ?? null);
        $caseType = match (true) {
            $crsScore !== null && $fswScore !== null => 'combined',
            $fswScore !== null => 'fsw',
            default => 'crs',
        };

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
            'primary_email' => $this->recoverEmail($id, 'GA_Assessment_Score'),
            'phone_mobile' => $this->nullableString($row['phone_mobile'] ?? null),
            'case_type' => $caseType,
            'crs_score' => $crsScore,
            'fsw_score' => $fswScore,
            'marital_status' => $this->nullableString($row['marital_status'] ?? null),
            'education_level' => $this->nullableString($row['education'] ?? null),
            'language_test_type' => $this->nullableString($row['what_language_test'] ?? null),
            'clb_speaking' => $this->nullableInt($row['speaking'] ?? null),
            'clb_listening' => $this->nullableInt($row['listening'] ?? null),
            'clb_reading' => $this->nullableInt($row['reading'] ?? null),
            'clb_writing' => $this->nullableInt($row['writing'] ?? null),
            'assigned_user_id' => $this->nullableUuid($row['assigned_user_id'] ?? null),
            'scores' => $scores,
        ];
    }
}
