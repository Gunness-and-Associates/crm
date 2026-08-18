<?php

namespace App\Support\Etl;

use App\Models\Student;
use App\Support\Etl\Concerns\MapsContactableFields;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load order position after Leads (BACKEND_BRIEF §13) — source
 * `ga_hq_students` (+ `ga_hq_students_cstm`), single table, HQ Learning Hub.
 */
final class StudentTransformer implements LegacyTransformer
{
    use MapsContactableFields;
    use NormalizesLegacyValues;

    public function key(): string
    {
        return 'students';
    }

    public function modelClass(): string
    {
        return Student::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')
            ->table('ga_hq_students')
            ->leftJoin('ga_hq_students_cstm', 'ga_hq_students_cstm.id_c', '=', 'ga_hq_students.id')
            ->select(['ga_hq_students.*', 'ga_hq_students_cstm.hot_lead_c', 'ga_hq_students_cstm.warm_lead_c']);

        if ($fromId !== null) {
            $query->where('ga_hq_students.id', '>=', $fromId);
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

        return array_merge(
            $this->contactableAttributes($row, $id, 'GA_HQ_Students'),
            [
                'get_started' => $this->nullableString($row['get_started'] ?? null),
                'status' => $this->nullableString($row['status'] ?? null),
                'how_hear' => $this->nullableString($row['how_hear'] ?? null),
                'hot_lead' => (bool) ($row['hot_lead_c'] ?? false),
                'warm_lead' => (bool) ($row['warm_lead_c'] ?? false),
            ],
        );
    }
}
