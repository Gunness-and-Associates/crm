<?php

namespace App\Support\Etl;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\Metadata\OptionList;
use App\Support\Etl\Concerns\MapsContactableFields;
use App\Support\Ingest\Canon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One reusable transformer configured per legacy GA_* lead module (see
 * LeadModuleSpec). `vertical` and `stage` are canonicalised the same way the
 * live ingest pipeline canonicalises incoming webform choices (Canon,
 * api-contract.md Part 3) — an unmatched value becomes null (vertical) or the
 * schema default 'new' (stage), logged, never guessed.
 */
final class LeadModuleTransformer implements LegacyTransformer
{
    use MapsContactableFields;

    private ?OptionList $verticalOptionList = null;

    private ?OptionList $stageOptionList = null;

    public function __construct(private readonly LeadModuleSpec $spec) {}

    public function key(): string
    {
        return $this->spec->key;
    }

    public function modelClass(): string
    {
        return Lead::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')->table($this->spec->table);

        if ($this->spec->cstmTable !== null) {
            $query->leftJoin(
                $this->spec->cstmTable,
                "{$this->spec->cstmTable}.id_c",
                '=',
                "{$this->spec->table}.id",
            )->select(["{$this->spec->table}.*", "{$this->spec->cstmTable}.*"]);
        }

        if ($fromId !== null) {
            $query->where("{$this->spec->table}.id", '>=', $fromId);
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

        $vertical = $this->spec->fixedVertical ?? Canon::matchOption(
            $this->nullableString($row[$this->spec->verticalDeriveColumn ?? ''] ?? null),
            $this->verticalOptionList(),
            $this->spec->table,
            $this->spec->verticalDeriveColumn ?? '',
        );

        $stage = $this->spec->stageColumn === null ? null : Canon::matchOption(
            $this->nullableString($row[$this->spec->stageColumn] ?? null),
            $this->stageOptionList(),
            $this->spec->table,
            $this->spec->stageColumn,
        );

        return array_merge(
            $this->contactableAttributes($row, $id, $this->spec->emailBeanModule),
            [
                'vertical' => $vertical,
                'stage' => $stage ?? LeadStage::New->value,
                'hot_lead' => $this->spec->hotLeadColumn !== null && (bool) ($row[$this->spec->hotLeadColumn] ?? false),
                'warm_lead' => $this->spec->warmLeadColumn !== null && (bool) ($row[$this->spec->warmLeadColumn] ?? false),
                'source' => $this->spec->table,
                'decline_reason' => $this->spec->declineReasonColumn === null
                    ? null
                    : $this->nullableString($row[$this->spec->declineReasonColumn] ?? null),
                'last_contacted_at' => $this->spec->lastContactedAtColumn === null
                    ? null
                    : LegacyDate::parse($this->nullableString($row[$this->spec->lastContactedAtColumn] ?? null)),
                'vertical_attributes' => $this->verticalAttributes($row),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function verticalAttributes(array $row): array
    {
        $attributes = [];
        foreach ($this->spec->verticalAttributeColumns as $column) {
            $value = $this->nullableString($row[$column] ?? null);
            if ($value === null) {
                continue;
            }

            $key = str_ends_with($column, '_c') ? substr($column, 0, -2) : $column;
            $attributes[$key] = $value;
        }

        return $attributes;
    }

    private function verticalOptionList(): OptionList
    {
        return $this->verticalOptionList ??= OptionList::query()->where('key', 'lead_vertical')->with('items')->firstOrFail();
    }

    private function stageOptionList(): OptionList
    {
        return $this->stageOptionList ??= OptionList::query()->where('key', 'lead_stage')->with('items')->firstOrFail();
    }
}
