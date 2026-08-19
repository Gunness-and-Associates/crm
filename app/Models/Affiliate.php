<?php

namespace App\Models;

use App\Models\Concerns\Contactable;
use App\Models\Concerns\HasActivities;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasEmailAddresses;
use App\Support\Acl\Aclable;
use App\Support\Acl\HasAcl;
use Database\Factories\AffiliateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Referral partners (source ga_affiliate, ~49 rows).
 *
 * @property string $id
 * @property string|null $username
 * @property string|null $affiliate_link
 * @property string|null $commission
 * @property string $status
 */
class Affiliate extends Model implements Aclable, AuditableContract
{
    use Auditable;
    use Contactable;
    use HasAcl;
    use HasActivities;
    use HasCustomFields;
    use HasEmailAddresses;

    /** @use HasFactory<AffiliateFactory> */
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
        'username', 'affiliate_link', 'commission', 'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge($this->contactableCasts(), [
            'commission' => 'decimal:2',
        ]);
    }
}
