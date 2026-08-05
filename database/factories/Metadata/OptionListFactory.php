<?php

namespace Database\Factories\Metadata;

use App\Models\Metadata\OptionList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionList>
 */
class OptionListFactory extends Factory
{
    protected $model = OptionList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->slug(2);

        return [
            'key' => $key,
            'label' => ucfirst(str_replace('-', ' ', $key)),
        ];
    }
}
