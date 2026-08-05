<?php

namespace App\Models\Metadata;

use Database\Factories\Metadata\ModuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $key
 * @property string $label
 * @property string|null $table_name
 * @property string $base_type
 * @property string|null $icon
 * @property bool $is_custom
 * @property bool $enabled
 * @property int $sort_order
 */
class Module extends Model
{
    /** @use HasFactory<ModuleFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'key', 'label', 'table_name', 'base_type', 'icon', 'is_custom', 'enabled', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_custom' => 'boolean', 'enabled' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return HasMany<Field, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }

    /** @return HasMany<Layout, $this> */
    public function layouts(): HasMany
    {
        return $this->hasMany(Layout::class);
    }
}
