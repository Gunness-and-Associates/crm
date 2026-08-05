<?php

namespace Database\Factories\Metadata;

use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionItem>
 */
class OptionItemFactory extends Factory
{
    protected $model = OptionItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->unique()->word();

        return [
            'option_list_id' => OptionList::factory(),
            'value' => $value,
            'label' => ucfirst($value),
            'sort_order' => 0,
        ];
    }
}
