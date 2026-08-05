<?php

namespace Database\Factories\Metadata;

use App\Models\Metadata\Change;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Change>
 */
class ChangeFactory extends Factory
{
    protected $model = Change::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => null,
            'kind' => 'field.created',
            'payload' => ['after' => ['name' => fake()->word()]],
            'status' => 'applied',
            'applied_at' => now(),
        ];
    }
}
