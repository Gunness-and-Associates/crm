<?php

namespace App\Support\Etl;

use App\Models\Company;
use App\Support\Etl\Concerns\MapsContactableFields;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load order position 2 (BACKEND_BRIEF §13) — source `ga_companies` (+
 * `ga_companies_cstm`, joined on `id_c = id`). Single source table, unlike Lead's
 * ~20 GA modules, so this is the simplest Contactable entity to migrate.
 */
final class CompanyTransformer implements LegacyTransformer
{
    use MapsContactableFields;
    use NormalizesLegacyValues;

    public function key(): string
    {
        return 'companies';
    }

    public function modelClass(): string
    {
        return Company::class;
    }

    public function query(?string $fromId): Builder
    {
        $query = DB::connection('legacy')
            ->table('ga_companies')
            ->leftJoin('ga_companies_cstm', 'ga_companies_cstm.id_c', '=', 'ga_companies.id')
            ->select([
                'ga_companies.*',
                'ga_companies_cstm.status_c',
                'ga_companies_cstm.email1_c',
                'ga_companies_cstm.pnp_program_c',
                'ga_companies_cstm.resume_submitted_c',
                'ga_companies_cstm.hot_lead_c',
                'ga_companies_cstm.warm_lead_c',
            ]);

        if ($fromId !== null) {
            $query->where('ga_companies.id', '>=', $fromId);
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

        // company_contact_status (main table) is the current field; status_c
        // (cstm) is an older custom addition the target schema folds into the
        // same column — prefer the main table's value, fall back to the cstm one.
        $contactStatus = $this->nullableString($row['company_contact_status'] ?? null)
            ?? $this->nullableString($row['status_c'] ?? null);

        return array_merge(
            $this->contactableAttributes($row, $id, 'GA_Companies', $this->nullableString($row['email1_c'] ?? null)),
            [
                'jobpostlink' => $this->nullableString($row['jobpostlink'] ?? null),
                'jobtitle' => $this->nullableString($row['jobtitle'] ?? null),
                'employees' => $this->nullableString($row['employees'] ?? null),
                'company_type' => $this->nullableString($row['company_type'] ?? null),
                'company_contact_status' => $contactStatus,
                'industry' => $this->nullableString($row['industry'] ?? null),
                'website' => $this->nullableString($row['website'] ?? null),
                'contact_person_name' => $this->nullableString($row['contact_person_name'] ?? null),
                'contact_person_phone' => $this->nullableString($row['contactpersonphone'] ?? null),
                'rating' => $this->nullableInt($row['rating'] ?? null),
                'lmia' => $this->nullableString($row['lmia'] ?? null),
                'pnp_program' => $this->legacyBool($row['pnp_program_c'] ?? null),
                'resume_submitted' => $this->legacyBool($row['resume_submitted_c'] ?? null),
                'hot_lead' => (bool) ($row['hot_lead_c'] ?? false),
                'warm_lead' => (bool) ($row['warm_lead_c'] ?? false),
            ],
        );
    }
}
