<?php

namespace App\Models\Metadata;

use Database\Factories\Metadata\OptionListFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $key
 * @property string $label
 */
class OptionList extends Model
{
    /** @use HasFactory<OptionListFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['key', 'label'];

    /** @return HasMany<OptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OptionItem::class)->orderBy('sort_order');
    }
}
