<?php

namespace Tests\Fixtures;

use App\Models\Concerns\Contactable;
use App\Models\Concerns\HasActivities;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasEmailAddresses;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Test-only model exercising the Contactable base, HasCustomFields (Z-2.1),
 * and HasActivities/HasEmailAddresses (Z-2.2). No shipped entity uses this
 * table; Company/Lead/etc. land in Z-2.4/Z-2.5 on top of the same traits.
 */
class ContactableFixture extends Model
{
    use Contactable;
    use HasActivities;
    use HasCustomFields;
    use HasEmailAddresses;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'contactable_fixtures';

    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'do_not_call', 'date_reviewed', 'primary_email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return $this->contactableCasts();
    }
}
