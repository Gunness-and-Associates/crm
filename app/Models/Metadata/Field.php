<?php

namespace App\Models\Metadata;

use Database\Factories\Metadata\FieldFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $module_id
 * @property string $name
 * @property string $type
 * @property string $label_key
 * @property string $storage
 * @property bool $required
 * @property string|null $default_value
 * @property array<string, mixed>|null $validation
 * @property bool $audited
 * @property bool $filterable
 * @property bool $sortable
 * @property bool $mass_update
 * @property bool $duplicate_merge
 * @property bool $reportable
 * @property bool $importable
 * @property string|null $help
 * @property string|null $comments
 * @property int|null $max_length
 * @property int|null $precision
 * @property int|null $scale
 * @property string|null $option_list_id
 * @property string|null $related_module_id
 * @property string|null $related_display_field
 * @property bool $is_custom
 * @property int $sort_order
 */
class Field extends Model
{
    /** @use HasFactory<FieldFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'module_id', 'name', 'type', 'label_key', 'storage', 'required', 'default_value',
        'validation', 'audited', 'filterable', 'sortable', 'mass_update', 'duplicate_merge',
        'reportable', 'importable', 'help', 'comments', 'max_length', 'precision', 'scale',
        'option_list_id', 'related_module_id', 'related_display_field', 'is_custom', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'validation' => 'array',
            'required' => 'boolean',
            'audited' => 'boolean',
            'filterable' => 'boolean',
            'sortable' => 'boolean',
            'mass_update' => 'boolean',
            'duplicate_merge' => 'boolean',
            'reportable' => 'boolean',
            'importable' => 'boolean',
            'is_custom' => 'boolean',
            'max_length' => 'integer',
            'precision' => 'integer',
            'scale' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Module, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /** @return BelongsTo<OptionList, $this> */
    public function optionList(): BelongsTo
    {
        return $this->belongsTo(OptionList::class);
    }

    /** @return BelongsTo<Module, $this> */
    public function relatedModule(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'related_module_id');
    }
}
