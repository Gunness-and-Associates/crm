<?php

namespace App\Support\Etl;

use App\Models\Call;
use App\Models\Company;
use App\Models\Lead;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use App\Support\Etl\Concerns\ResolvesActivitySubjects;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Only 18 calls exist in the audited source at all, 17 of which are parented
 * to the dead stock `Accounts` module — this migrates the one resolvable
 * call plus any others reachable via a junction table.
 */
final class CallTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;
    use ResolvesActivitySubjects;

    public function key(): string
    {
        return 'activities_calls';
    }

    public function modelClass(): string
    {
        return Call::class;
    }

    public function query(?string $fromId): Builder
    {
        $ids = array_keys($this->resolveActivitySubjects($this->specs(), 'calls'));

        $query = DB::connection('legacy')->table('calls')->whereIn('id', $ids);

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

        $subject = $this->resolveActivitySubjects($this->specs(), 'calls')[$id] ?? null;
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
            'direction' => strtolower($this->nullableString($row['direction'] ?? null) ?? 'outbound'),
            'date_start' => LegacyDate::parse($this->nullableString($row['date_start'] ?? null)),
            'duration_minutes' => $hours * 60 + $minutes,
            'summary' => $this->nullableString($row['description'] ?? null),
        ];
    }

    /**
     * @return list<ActivitySourceSpec>
     */
    private function specs(): array
    {
        return [
            ActivitySourceSpec::viaJunction('ga_companies_calls_c', 'ga_companies', Company::class),
            ActivitySourceSpec::viaJunction('ga_lmia_course_calls_c', 'ga_lmia_course', Lead::class),
        ];
    }
}
