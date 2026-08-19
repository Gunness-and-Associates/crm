<?php

namespace App\Models;

use App\Enums\LeadStage;
use App\Enums\LeadVertical;
use App\Models\Concerns\Contactable;
use App\Models\Concerns\HasActivities;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasEmailAddresses;
use App\Support\Acl\Aclable;
use App\Support\Acl\HasAcl;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Consolidates 23 source GA_* lead modules (BACKEND_BRIEF §7.4). Study Permit
 * and LMIA are verticals here, not separate entities.
 *
 * @property string $id
 * @property LeadVertical|null $vertical
 * @property LeadStage $stage
 * @property array<string, mixed>|null $vertical_attributes
 * @property bool $hot_lead
 * @property bool $warm_lead
 * @property string|null $source
 * @property string|null $decline_reason
 * @property Carbon|null $last_contacted_at
 * @property Carbon|null $next_follow_up_at
 */
class Lead extends Model implements Aclable, AuditableContract
{
    use Auditable;
    use Contactable;
    use HasAcl;
    use HasActivities;
    use HasCustomFields;
    use HasEmailAddresses;

    /** @use HasFactory<LeadFactory> */
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
        'vertical', 'stage', 'vertical_attributes', 'hot_lead', 'warm_lead', 'source', 'decline_reason',
        'last_contacted_at', 'next_follow_up_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge($this->contactableCasts(), [
            'vertical' => LeadVertical::class,
            'stage' => LeadStage::class,
            'vertical_attributes' => 'array',
            'hot_lead' => 'boolean',
            'warm_lead' => 'boolean',
            'last_contacted_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
        ]);
    }
}
