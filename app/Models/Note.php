<?php

namespace App\Models;

use App\Models\Concerns\Subjectable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $name
 * @property string|null $body
 * @property string|null $attachment_path
 */
class Note extends Model
{
    use HasUuids;
    use SoftDeletes;
    use Subjectable;

    /** @var list<string> */
    protected $fillable = [
        'subject_type', 'subject_id', 'assigned_user_id', 'created_by',
        'name', 'body', 'attachment_path',
    ];
}
