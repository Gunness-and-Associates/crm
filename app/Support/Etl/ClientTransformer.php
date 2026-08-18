<?php

namespace App\Support\Etl;

use App\Models\Client;
use App\Support\Etl\Concerns\MapsContactableFields;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load order position after Leads (BACKEND_BRIEF §13) — source `ga_clients`
 * (+ `ga_clients_cstm`), the main Client source table. `ga_clientdevelopment2`,
 * `ga_clientdevelopment3` and `ga_imm_client` are registered separately (the
 * latter two via BareContactableTransformer — they carry no differentiating
 * columns in the audited schema).
 */
final class ClientTransformer implements LegacyTransformer
{
    use MapsContactableFields;
    use NormalizesLegacyValues;

    public function key(): string
    {
        return 'clients';
    }

    public function modelClass(): string
    {
        return Client::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')
            ->table('ga_clients')
            ->leftJoin('ga_clients_cstm', 'ga_clients_cstm.id_c', '=', 'ga_clients.id')
            ->select(['ga_clients.*', 'ga_clients_cstm.status_c']);

        if ($fromId !== null) {
            $query->where('ga_clients.id', '>=', $fromId);
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

        // client_status (main table) is the current field; status_c (cstm) is
        // an older custom addition folded into the same target column — same
        // fallback pattern as CompanyTransformer's company_contact_status.
        $clientStatus = $this->nullableString($row['client_status'] ?? null)
            ?? $this->nullableString($row['status_c'] ?? null);

        return array_merge(
            $this->contactableAttributes($row, $id, 'GA_Clients'),
            [
                'client_status' => $clientStatus,
                'country' => $this->nullableString($row['country'] ?? null),
                'gender' => $this->nullableString($row['gender'] ?? null),
                'dob' => LegacyDate::parseFlexibleDate($this->nullableString($row['dob'] ?? null)),
                'marital_status' => $this->nullableString($row['marital_status'] ?? null),
                'employment_status' => $this->nullableString($row['employment_status'] ?? null),
                'highest_education_level' => $this->nullableString($row['highest_education_level'] ?? null),
                'english_language_level' => $this->nullableString($row['english_language_level'] ?? null),
                'lead_source' => $this->nullableString($row['lead_source'] ?? null),
                'hear_about_us' => $this->nullableString($row['hear_about_us'] ?? null),
                'current_status_in_canada' => $this->nullableString($row['current_status_in_canada'] ?? null),
                'interested_programs' => $this->nullableString($row['interested_programs'] ?? null),
                'worth_money' => $this->legacyBool($row['worth_money'] ?? null),
                'work_experience_year' => $this->nullableInt($row['work_experience_year'] ?? null),
                'refused_a_visa' => $this->legacyBool($row['refused_a_visa'] ?? null),
                'have_relative_canada' => $this->legacyBool($row['have_relative_canada'] ?? null),
                'ever_visited_canada' => $this->legacyBool($row['ever_visited_canada'] ?? null),
                'briefly_describe_issue' => $this->nullableString($row['briefly_describe_issue'] ?? null),
                'utm_source' => $this->nullableString($row['utm_source'] ?? null),
                'utm_medium' => $this->nullableString($row['utm_medium'] ?? null),
                'utm_campaign' => $this->nullableString($row['utm_campaign'] ?? null),
            ],
        );
    }
}
