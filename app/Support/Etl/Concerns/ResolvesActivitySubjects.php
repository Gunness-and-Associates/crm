<?php

namespace App\Support\Etl\Concerns;

use App\Support\Etl\ActivitySourceSpec;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which (subject_type, subject_id) each legacy activity row belongs
 * to, across however many junction tables and/or direct parent_type values
 * feed that activity type — computed once per transformer instance (a few
 * thousand rows at most, cheap to hold in memory), then looked up per row
 * in transform() rather than re-queried.
 */
trait ResolvesActivitySubjects
{
    use NormalizesLegacyValues;

    /** @var array<string, array{class: string, id: string}>|null */
    private ?array $subjectMap = null;

    /**
     * @param  list<ActivitySourceSpec>  $specs
     * @return array<string, array{class: string, id: string}> activity row id => subject
     */
    private function resolveActivitySubjects(array $specs, string $activityTable): array
    {
        if ($this->subjectMap !== null) {
            return $this->subjectMap;
        }

        $map = [];
        foreach ($specs as $spec) {
            if ($spec->isJunction()) {
                $moduleCol = $spec->moduleIdColumn($activityTable);
                $activityCol = $spec->activityIdColumn($activityTable);
                $rows = DB::connection('legacy')->table((string) $spec->junctionTable)
                    ->where('deleted', 0)
                    ->whereNotNull($moduleCol)
                    ->whereNotNull($activityCol)
                    ->get([$moduleCol, $activityCol]);

                foreach ($rows as $row) {
                    $activityId = $this->stringValue($row->{$activityCol} ?? null);
                    $map[$activityId] = ['class' => $spec->subjectClass, 'id' => $this->stringValue($row->{$moduleCol} ?? null)];
                }

                continue;
            }

            $rows = DB::connection('legacy')->table($activityTable)
                ->where('parent_type', $spec->directParentType)
                ->whereNotNull('parent_id')
                ->get(['id', 'parent_id']);

            foreach ($rows as $row) {
                $map[$this->stringValue($row->id ?? null)] = ['class' => $spec->subjectClass, 'id' => $this->stringValue($row->parent_id ?? null)];
            }
        }

        return $this->subjectMap = $map;
    }
}
