<?php

namespace Database\Factories\Metadata;

use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Layout>
 */
class LayoutFactory extends Factory
{
    protected $model = Layout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module_id' => Module::factory(),
            'view' => 'list',
            'definition' => [
                'version' => 1,
                'view' => 'list',
                'module' => 'leads',
                'content' => ['columns' => [['field' => 'full_name', 'priority' => 1, 'link' => true]]],
            ],
            'version' => 1,
            'is_published' => false,
        ];
    }
}
