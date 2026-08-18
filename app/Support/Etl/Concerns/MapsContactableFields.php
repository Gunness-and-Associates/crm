<?php

namespace App\Support\Etl\Concerns;

use App\Support\Etl\LegacyDate;

/**
 * Every GA person/lead table shares the same ~25 base columns (BACKEND_BRIEF
 * §7.1 "Contactable"). CompanyTransformer had this block written out longhand
 * before the Lead modules (18+ of them) made the duplication unreasonable —
 * shared here so every Contactable-mapping transformer stays consistent,
 * including the FK-safety guard (Z-6.1 CompanyTransformer fix) on
 * assigned_user_id/created_by/modified_by.
 */
trait MapsContactableFields
{
    use NormalizesLegacyValues;
    use RecoversLegacyEmail;

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function contactableAttributes(array $row, string $id, string $emailBeanModule, ?string $fallbackEmail = null): array
    {
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
            // A couple of source tables spell this whatsapp_phone instead of the
            // usual whatsapp_number_c/whatsapp_number — check both.
            'whatsapp_number' => $this->nullableString($row['whatsapp_number'] ?? $row['whatsapp_phone'] ?? null),
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
            'primary_email' => $this->recoverEmail($id, $emailBeanModule) ?? $fallbackEmail,
            'assigned_user_id' => $this->nullableUuid($row['assigned_user_id'] ?? null),
            'created_by' => $this->nullableUuid($row['created_by'] ?? null),
            'modified_by' => $this->nullableUuid($row['modified_user_id'] ?? null),
        ];
    }
}
