<?php

namespace App\Models;

use App\Models\Concerns\Contactable;
use App\Models\Concerns\HasActivities;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasEmailAddresses;
use App\Support\Acl\Aclable;
use App\Support\Acl\HasAcl;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Post-conversion client lifecycle (source ga_clients + ga_clientdevelopment2/3
 * + ga_imm_client, ~325 rows).
 *
 * @property string $id
 * @property string|null $client_status
 * @property string|null $case_type
 * @property string|null $country
 * @property string|null $gender
 * @property Carbon|null $dob
 * @property string|null $marital_status
 * @property string|null $employment_status
 * @property string|null $highest_education_level
 * @property string|null $english_language_level
 * @property string|null $lead_source
 * @property string|null $hear_about_us
 * @property string|null $current_status_in_canada
 * @property string|null $interested_programs
 * @property bool $worth_money
 * @property int|null $work_experience_year
 * @property bool $refused_a_visa
 * @property bool $have_relative_canada
 * @property bool $ever_visited_canada
 * @property string|null $briefly_describe_issue
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $retainer_amount
 * @property string|null $fee_status
 * @property Carbon|null $next_action_at
 */
class Client extends Model implements Aclable, AuditableContract
{
    use Auditable;
    use Contactable;
    use HasAcl;
    use HasActivities;
    use HasCustomFields;
    use HasEmailAddresses;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'salutation', 'first_name', 'last_name', 'title', 'department', 'description',
        'do_not_call', 'phone_home', 'phone_mobile', 'phone_work', 'phone_other', 'phone_fax',
        'whatsapp_number', 'primary_address_street', 'primary_address_city', 'primary_address_state',
        'primary_address_postalcode', 'primary_address_country', 'alt_address_street', 'alt_address_city',
        'alt_address_state', 'alt_address_postalcode', 'alt_address_country', 'lawful_basis',
        'date_reviewed', 'lawful_basis_source', 'primary_email', 'assigned_user_id', 'created_by', 'modified_by',
        'client_status', 'case_type', 'country', 'gender', 'dob', 'marital_status', 'employment_status',
        'highest_education_level', 'english_language_level', 'lead_source', 'hear_about_us',
        'current_status_in_canada', 'interested_programs', 'worth_money', 'work_experience_year',
        'refused_a_visa', 'have_relative_canada', 'ever_visited_canada', 'briefly_describe_issue',
        'utm_source', 'utm_medium', 'utm_campaign', 'retainer_amount', 'fee_status', 'next_action_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge($this->contactableCasts(), [
            'dob' => 'date',
            'worth_money' => 'boolean',
            'work_experience_year' => 'integer',
            'refused_a_visa' => 'boolean',
            'have_relative_canada' => 'boolean',
            'ever_visited_canada' => 'boolean',
            'retainer_amount' => 'decimal:2',
            'next_action_at' => 'datetime',
        ]);
    }
}
