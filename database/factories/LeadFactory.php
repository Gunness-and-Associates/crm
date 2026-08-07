<?php

namespace Database\Factories;

use App\Enums\LeadStage;
use App\Enums\LeadVertical;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'primary_email' => fake()->unique()->safeEmail(),
            'phone_mobile' => fake()->phoneNumber(),
            'vertical' => fake()->randomElement(LeadVertical::cases()),
            'stage' => LeadStage::New,
            'do_not_call' => false,
            'hot_lead' => false,
            'warm_lead' => false,
        ];
    }
}
