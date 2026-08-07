<?php

namespace Database\Factories;

use App\Models\Assessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
{
    protected $model = Assessment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'primary_email' => fake()->unique()->safeEmail(),
            'case_type' => 'crs',
            'status' => 'requested',
            'crs_score' => fake()->numberBetween(300, 500),
            'marital_status' => fake()->randomElement(['single', 'married']),
            'clb_speaking' => fake()->numberBetween(4, 10),
            'clb_listening' => fake()->numberBetween(4, 10),
            'clb_reading' => fake()->numberBetween(4, 10),
            'clb_writing' => fake()->numberBetween(4, 10),
        ];
    }
}
