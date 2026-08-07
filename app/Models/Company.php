<?php

namespace App\Models;

use App\Models\Concerns\Contactable;
use App\Models\Concerns\HasActivities;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasEmailAddresses;
use App\Support\Acl\Aclable;
use App\Support\Acl\HasAcl;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Employer / recruiter directory (source ga_companies, ~21,000 rows).
 *
 * @property string $id
 * @property int|null $rating
 * @property string|null $lmia
 * @property string|null $jobpostlink
 * @property string|null $jobtitle
 * @property string|null $employees
 * @property string|null $company_type
 * @property string|null $company_contact_status
 * @property string|null $industry
 * @property string|null $website
 * @property string|null $contact_person_name
 * @property string|null $contact_person_phone
 * @property bool $pnp_program
 * @property bool $resume_submitted
 * @property bool $hot_lead
 * @property bool $warm_lead
 */
class Company extends Model implements Aclable
{
    use Contactable;
    use HasAcl;
    use HasActivities;
    use HasCustomFields;
    use HasEmailAddresses;

    /** @use HasFactory<CompanyFactory> */
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
        'rating', 'lmia', 'jobpostlink', 'jobtitle', 'employees', 'company_type', 'company_contact_status',
        'industry', 'website', 'contact_person_name', 'contact_person_phone',
        'pnp_program', 'resume_submitted', 'hot_lead', 'warm_lead',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge($this->contactableCasts(), [
            'rating' => 'integer',
            'pnp_program' => 'boolean',
            'resume_submitted' => 'boolean',
            'hot_lead' => 'boolean',
            'warm_lead' => 'boolean',
        ]);
    }
}
