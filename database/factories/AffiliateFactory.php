<?php

namespace Database\Factories;

use App\Models\Affiliate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Affiliate>
 */
class AffiliateFactory extends Factory
{
    protected $model = Affiliate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'primary_email' => fake()->unique()->safeEmail(),
            'username' => fake()->unique()->userName(),
            'affiliate_link' => fake()->url(),
            'commission' => fake()->randomFloat(2, 5, 20),
            'status' => 'active',
            'do_not_call' => false,
        ];
    }
}
