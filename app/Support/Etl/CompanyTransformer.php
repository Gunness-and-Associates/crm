<?php

namespace App\Support\Etl;

use App\Models\Company;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use App\Support\Etl\Concerns\RecoversLegacyEmail;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Load order position 2 (BACKEND_BRIEF §13) — source `ga_companies` (+
 * `ga_companies_cstm`, joined on `id_c = id`). Single source table, unlike Lead's
 * ~20 GA modules, so this is the simplest Contactable entity to migrate.
 */
final class CompanyTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;
    use RecoversLegacyEmail;

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

        // BACKEND_BRIEF §13: "deleted = 1 -> set deleted_at from date_modified."
        $deletedAt = (bool) ($row['deleted'] ?? false)
            ? LegacyDate::parse($this->nullableString($row['date_modified'] ?? null))
            : null;

        return [
            'id' => $id,
            'deleted_at' => $deletedAt,
            'salutation' => $this->nullableString($row['salutation'] ?? null),
            'first_name' => $this->nullableString($row['first_name'] ?? null),
            'last_name' => $this->nullableString($row['last_name'] ?? null),
            'title' => $this->nullableString($row['title'] ?? null),
            'department' => $this->nullableString($row['department'] ?? null),
            'description' => $this->nullableString($row['description'] ?? null),
            'do_not_call' => (bool) ($row['do_not_call'] ?? false),
            'phone_home' => $this->nullableString($row['phone_home'] ?? null),
            'phone_mobile' => $this->nullableString($row['phone_mobile'] ?? null),
            'phone_work' => $this->nullableString($row['phone_work'] ?? null),
            'phone_other' => $this->nullableString($row['phone_other'] ?? null),
            'phone_fax' => $this->nullableString($row['phone_fax'] ?? null),
            'primary_address_street' => $this->nullableString($row['primary_address_street'] ?? null),
            'primary_address_city' => $this->nullableString($row['primary_address_city'] ?? null),
            'primary_address_state' => $this->nullableString($row['primary_address_state'] ?? null),
            'primary_address_postalcode' => $this->nullableString($row['primary_address_postalcode'] ?? null),
            'primary_address_country' => $this->nullableString($row['primary_address_country'] ?? null),
            'alt_address_street' => $this->nullableString($row['alt_address_street'] ?? null),
            'alt_address_city' => $this->nullableString($row['alt_address_city'] ?? null),
            'alt_address_state' => $this->nullableString($row['alt_address_state'] ?? null),
            'alt_address_postalcode' => $this->nullableString($row['alt_address_postalcode'] ?? null),
            'alt_address_country' => $this->nullableString($row['alt_address_country'] ?? null),
            'lawful_basis' => $this->nullableString($row['lawful_basis'] ?? null),
            'date_reviewed' => LegacyDate::parseDate($this->nullableString($row['date_reviewed'] ?? null)),
            'lawful_basis_source' => $this->nullableString($row['lawful_basis_source'] ?? null),
            'primary_email' => $this->recoverEmail($id, 'GA_Companies') ?? $this->nullableString($row['email1_c'] ?? null),
            'assigned_user_id' => $this->nullableString($row['assigned_user_id'] ?? null),
            'created_by' => $this->nullableString($row['created_by'] ?? null),
            'modified_by' => $this->nullableString($row['modified_user_id'] ?? null),
            'rating' => $this->nullableInt($row['rating'] ?? null),
            'lmia' => $this->nullableString($row['lmia'] ?? null),
            'jobpostlink' => $this->nullableString($row['jobpostlink'] ?? null),
            'jobtitle' => $this->nullableString($row['jobtitle'] ?? null),
            'employees' => $this->nullableString($row['employees'] ?? null),
            'company_type' => $this->nullableString($row['company_type'] ?? null),
            'company_contact_status' => $contactStatus,
            'industry' => $this->nullableString($row['industry'] ?? null),
            'website' => $this->nullableString($row['website'] ?? null),
            'contact_person_name' => $this->nullableString($row['contact_person_name'] ?? null),
            'contact_person_phone' => $this->nullableString($row['contactpersonphone'] ?? null),
            'pnp_program' => $this->legacyBool($row['pnp_program_c'] ?? null),
            'resume_submitted' => $this->legacyBool($row['resume_submitted_c'] ?? null),
            'hot_lead' => (bool) ($row['hot_lead_c'] ?? false),
            'warm_lead' => (bool) ($row['warm_lead_c'] ?? false),
        ];
    }
}
