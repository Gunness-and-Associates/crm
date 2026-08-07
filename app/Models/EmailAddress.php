<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per unique address (BACKEND_BRIEF §7.2). Linked to any Contactable
 * record through the email_address_relations pivot — see HasEmailAddresses.
 *
 * @property string $id
 * @property string $email
 * @property bool $is_invalid
 * @property bool $opted_out
 */
class EmailAddress extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['email', 'is_invalid', 'opted_out'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_invalid' => 'boolean', 'opted_out' => 'boolean'];
    }
}
