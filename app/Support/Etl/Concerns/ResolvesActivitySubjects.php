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

            // Unlike a junction table (which only ever links to a row that
            // really existed in the source), a direct parent_type/parent_id
            // pair can point at nothing real at all — several historical
            // module-name aliases (hamid_*, SZ_*) only partially match their
            // current-day table (e.g. ~72% for hamid_express_entry_requests,
            // ~12% for SZ_Study). subject_id carries no DB-level FK (it is
            // polymorphic), so an unresolvable parent_id would silently
            // become a dangling reference instead of erroring — verify
            // against the *target* table before accepting it.
            $parentIds = $rows->pluck('parent_id')->filter()->map($this->stringValue(...))->all();
            $realIdSet = $spec->subjectClass::withoutGlobalScopes()->whereIn('id', $parentIds)->pluck('id')
                ->map($this->stringValue(...))
                ->flip()
                ->all();

            foreach ($rows as $row) {
                $parentId = $this->stringValue($row->parent_id ?? null);
                if (! isset($realIdSet[$parentId])) {
                    continue;
                }

                $map[$this->stringValue($row->id ?? null)] = ['class' => $spec->subjectClass, 'id' => $parentId];
            }
        }

        return $this->subjectMap = $map;
    }
}
