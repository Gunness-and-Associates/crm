<?php

namespace App\Support\Etl\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Several legacy tables have a target-unique column (users.username,
 * affiliates.username, ...) with real duplicate values across distinct
 * source rows — first discovered via 256+ cascading FK failures when two
 * `api_user` rows collided on the Users migration. Rather than silently
 * losing every row but the first to the unique constraint, the row with
 * `deleted=0`, then the most recently modified, keeps the plain value;
 * every other row sharing that value gets a short id suffix so all of them
 * still migrate.
 */
trait DisambiguatesUniqueColumn
{
    /**
     * @return array<string, string> source id => disambiguated value, only
     *                               for rows that are NOT the winner of their
     *                               collision group
     */
    private function uniqueColumnOverrides(string $table, string $column): array
    {
        $groups = DB::connection('legacy')->table($table)
            ->select(['id', "{$column} as value", 'deleted', 'date_modified'])
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->get()
            ->groupBy('value');

        $overrides = [];
        foreach ($groups as $value => $rows) {
            if ($rows->count() < 2) {
                continue;
            }

            $sorted = $rows->sortBy([
                ['deleted', 'asc'],
                ['date_modified', 'desc'],
            ]);
            $winner = $sorted->first();
            $winnerId = $winner === null ? null : $this->stringValue($winner->id);

            foreach ($rows as $row) {
                $rowId = $this->stringValue($row->id);
                if ($rowId !== '' && $rowId !== $winnerId) {
                    $overrides[$rowId] = sprintf('%s-%s', $this->stringValue($value), substr($rowId, 0, 8));
                }
            }
        }

        return $overrides;
    }
}
