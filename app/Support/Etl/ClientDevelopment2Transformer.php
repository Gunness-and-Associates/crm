<?php

namespace App\Support\Etl;

use App\Models\Client;
use App\Support\Etl\Concerns\MapsContactableFields;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Source `ga_clientdevelopment2` — pure Contactable base plus a `status`
 * column, no `_cstm` sidecar.
 */
final class ClientDevelopment2Transformer implements LegacyTransformer
{
    use MapsContactableFields;
    use NormalizesLegacyValues;

    public function key(): string
    {
        return 'clients_development2';
    }

    public function modelClass(): string
    {
        return Client::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')->table('ga_clientdevelopment2');

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

        return array_merge(
            $this->contactableAttributes($row, $id, 'GA_ClientDevelopment2'),
            ['client_status' => $this->nullableString($row['status'] ?? null)],
        );
    }
}
