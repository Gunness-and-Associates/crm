<?php

namespace App\Models\Metadata;

use Database\Factories\Metadata\LayoutFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $module_id
 * @property string $view
 * @property array<string, mixed> $definition
 * @property int $version
 * @property bool $is_published
 */
class Layout extends Model
{
    /** @use HasFactory<LayoutFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['module_id', 'view', 'definition', 'version', 'is_published'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition' => 'array', 'version' => 'integer', 'is_published' => 'boolean'];
    }

    /** @return BelongsTo<Module, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
