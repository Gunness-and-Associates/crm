<?php

namespace App\Support\Etl;

use App\Models\Lead;
use App\Models\Meeting;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use App\Support\Etl\Concerns\ResolvesActivitySubjects;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Only 26 meetings exist in the audited source at all, 22 of which are
 * parented to the dead stock `Accounts` module.
 */
final class MeetingTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;
    use ResolvesActivitySubjects;

    public function key(): string
    {
        return 'activities_meetings';
    }

    public function modelClass(): string
    {
        return Meeting::class;
    }

    public function query(?string $fromId): Builder
    {
        $ids = array_keys($this->resolveActivitySubjects($this->specs(), 'meetings'));

        $query = DB::connection('legacy')->table('meetings')->whereIn('id', $ids);

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

        $subject = $this->resolveActivitySubjects($this->specs(), 'meetings')[$id] ?? null;
        if ($subject === null) {
            return null;
        }

        $deletedAt = (bool) ($row['deleted'] ?? false)
            ? LegacyDate::parse($this->nullableString($row['date_modified'] ?? null))
            : null;

        $hours = $this->nullableInt($row['duration_hours'] ?? null) ?? 0;
        $minutes = $this->nullableInt($row['duration_minutes'] ?? null) ?? 0;

        return [
            'id' => $id,
            'deleted_at' => $deletedAt,
            'subject_type' => $subject['class'],
            'subject_id' => $subject['id'],
            'assigned_user_id' => $this->nullableUuid($row['assigned_user_id'] ?? null),
            'created_by' => $this->nullableUuid($row['created_by'] ?? null),
            'name' => $this->nullableString($row['name'] ?? null) ?? 'Meeting',
            'location' => $this->nullableString($row['location'] ?? null),
            'date_start' => LegacyDate::parse($this->nullableString($row['date_start'] ?? null)),
            'date_end' => LegacyDate::parse($this->nullableString($row['date_end'] ?? null)),
            'duration_minutes' => $hours * 60 + $minutes,
            'status' => strtolower($this->nullableString($row['status'] ?? null) ?? 'planned'),
            'description' => $this->nullableString($row['description'] ?? null),
        ];
    }

    /**
     * @return list<ActivitySourceSpec>
     */
    private function specs(): array
    {
        return [
            ActivitySourceSpec::viaJunction('ga_lmia_course_meetings_c', 'ga_lmia_course', Lead::class),
        ];
    }
}
