<?php

namespace Database\Factories\Metadata;

use App\Models\Metadata\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 */
class ModuleFactory extends Factory
{
    protected $model = Module::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->word();

        return [
            'key' => $key,
            'label' => ucfirst($key),
            'table_name' => $key,
            'base_type' => 'generic',
            'is_custom' => false,
            'enabled' => true,
            'sort_order' => 0,
        ];
    }
}
