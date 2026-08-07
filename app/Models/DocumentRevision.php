<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $document_id
 * @property int $revision_number
 * @property string $file_path
 * @property string|null $file_mime_type
 * @property string|null $created_by
 */
class DocumentRevision extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['document_id', 'revision_number', 'file_path', 'file_mime_type', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision_number' => 'integer'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
