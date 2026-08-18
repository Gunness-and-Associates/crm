<?php

namespace App\Support\Etl;

use App\Support\Etl\Concerns\MapsContactableFields;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A handful of legacy tables (`ga_clientdevelopment3`, `ga_imm_client`) carry
 * no columns beyond the shared Contactable base in the audited schema — no
 * `_cstm` sidecar, no entity-specific field. Rather than a near-empty
 * dedicated class per table, one instance configured per table covers them.
 */
final class BareContactableTransformer implements LegacyTransformer
{
    use MapsContactableFields;
    use NormalizesLegacyValues;

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(
        private readonly string $transformerKey,
        private readonly string $table,
        private readonly string $emailBeanModule,
        private readonly string $modelClass,
    ) {}

    public function key(): string
    {
        return $this->transformerKey;
    }

    public function modelClass(): string
    {
        return $this->modelClass;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')->table($this->table);

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

        return $this->contactableAttributes($row, $id, $this->emailBeanModule);
    }
}
