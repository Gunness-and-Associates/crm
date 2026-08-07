<?php

namespace App\Models;

use App\Models\Concerns\Subjectable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $subject_line
 * @property string $from_address
 * @property list<string> $to_addresses
 * @property list<string>|null $cc_addresses
 * @property string|null $body_html
 * @property string|null $body_text
 * @property string $status
 * @property Carbon|null $sent_at
 */
class Email extends Model
{
    use HasUuids;
    use SoftDeletes;
    use Subjectable;

    /** @var list<string> */
    protected $fillable = [
        'subject_type', 'subject_id', 'assigned_user_id', 'created_by',
        'subject_line', 'from_address', 'to_addresses', 'cc_addresses',
        'body_html', 'body_text', 'status', 'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['to_addresses' => 'array', 'cc_addresses' => 'array', 'sent_at' => 'datetime'];
    }
}
