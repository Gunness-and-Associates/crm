<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'primary_email' => fake()->unique()->safeEmail(),
            'client_status' => 'onboarding',
            'case_type' => fake()->randomElement(['ExpressEntry', 'StudyPermit', 'LMIA']),
            'country' => fake()->country(),
            'fee_status' => 'outstanding',
            'do_not_call' => false,
            'worth_money' => false,
            'refused_a_visa' => false,
            'have_relative_canada' => false,
            'ever_visited_canada' => false,
        ];
    }
}
