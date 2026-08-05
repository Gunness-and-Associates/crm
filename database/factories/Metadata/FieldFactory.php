<?php

namespace Database\Factories\Metadata;

use App\Models\Metadata\Field;
use App\Models\Metadata\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Field>
 */
class FieldFactory extends Factory
{
    protected $model = Field::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'module_id' => Module::factory(),
            'name' => $name,
            'type' => 'text',
            'label_key' => 'LBL_'.strtoupper($name),
            'storage' => 'column',
            'required' => false,
            'max_length' => 255,
            'is_custom' => true,
            'sort_order' => 0,
        ];
    }
}
