<?php

namespace App\Models;

use App\Models\Concerns\Subjectable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $name
 * @property string $file_path
 * @property string|null $file_mime_type
 * @property string|null $category
 * @property string $status
 * @property bool $is_template
 */
class Document extends Model
{
    use HasUuids;
    use SoftDeletes;
    use Subjectable;

    /** @var list<string> */
    protected $fillable = [
        'subject_type', 'subject_id', 'assigned_user_id', 'created_by',
        'name', 'file_path', 'file_mime_type', 'category', 'status', 'is_template',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_template' => 'boolean'];
    }

    /** @return HasMany<DocumentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class)->orderByDesc('revision_number');
    }
}
