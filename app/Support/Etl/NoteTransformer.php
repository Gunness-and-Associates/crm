<?php

namespace App\Support\Etl;

use App\Models\Assessment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Note;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use App\Support\Etl\Concerns\ResolvesActivitySubjects;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load order position after the core entities, before Calls/Meetings/
 * Documents (BACKEND_BRIEF §13 "activities"). Notes are the biggest activity
 * type (28,600 rows) but most are unresolvable: 19,862 point at the dead
 * stock `Accounts` module (1 row in this database, none matching) and 8,316
 * are attached to Emails (an email-attachment note, not a business record —
 * out of scope until Email activities exist). Per-row policy: skip anything
 * whose parent doesn't resolve to an entity we've actually migrated, rather
 * than guessing or migrating orphaned/unlinked notes.
 */
final class NoteTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;
    use ResolvesActivitySubjects;

    public function key(): string
    {
        return 'activities_notes';
    }

    public function modelClass(): string
    {
        return Note::class;
    }

    public function query(?string $fromId): Builder
    {
        $ids = array_keys($this->resolveActivitySubjects($this->specs(), 'notes'));

        $query = DB::connection('legacy')->table('notes')->whereIn('id', $ids);

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

        $subject = $this->resolveActivitySubjects($this->specs(), 'notes')[$id] ?? null;
        if ($subject === null) {
            return null;
        }

        $deletedAt = (bool) ($row['deleted'] ?? false)
            ? LegacyDate::parse($this->nullableString($row['date_modified'] ?? null))
            : null;

        return [
            'id' => $id,
            'deleted_at' => $deletedAt,
            'subject_type' => $subject['class'],
            'subject_id' => $subject['id'],
            'assigned_user_id' => $this->nullableUuid($row['assigned_user_id'] ?? null),
            'created_by' => $this->nullableUuid($row['created_by'] ?? null),
            'name' => $this->nullableString($row['name'] ?? null),
            'body' => $this->nullableString($row['description'] ?? null),
            'attachment_path' => $this->nullableString($row['filename'] ?? null),
        ];
    }

    /**
     * @return list<ActivitySourceSpec>
     */
    private function specs(): array
    {
        return [
            ActivitySourceSpec::viaJunction('ga_companies_notes_c', 'ga_companies', Company::class),
            ActivitySourceSpec::viaJunction('ga_lmia_course_notes_c', 'ga_lmia_course', Lead::class),
            ActivitySourceSpec::viaJunction('ga_study_notes_c', 'ga_study', Lead::class),
            ActivitySourceSpec::viaJunction('ga_assessment_score_notes_c', 'ga_assessment_score', Assessment::class),
            ActivitySourceSpec::viaJunction('ga_galead_notes_c', 'ga_galead', Lead::class),
            ActivitySourceSpec::viaJunction('ga_imm_can_notes_c', 'ga_imm_can', Lead::class),
            ActivitySourceSpec::viaJunction('ga_usa_notes_c', 'ga_usa', Lead::class),
            ActivitySourceSpec::viaJunction('ga_imm_biz_notes_c', 'ga_imm_biz', Lead::class),
            ActivitySourceSpec::viaJunction('ga_bd1_notes_c', 'ga_bd1', Lead::class),
            ActivitySourceSpec::viaJunction('ga_clientdevelopment2_notes_c', 'ga_clientdevelopment2', Client::class),
            ActivitySourceSpec::viaJunction('ga_applicant_notes_c', 'ga_applicant', Lead::class),
            ActivitySourceSpec::viaParentType('GA_Applicant', Lead::class),
            ActivitySourceSpec::viaParentType('Clients', Client::class),
        ];
    }
}
