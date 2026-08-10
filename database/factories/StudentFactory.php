<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

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
            'get_started' => fake()->randomElement(['not_started', 'in_progress', 'completed']),
            'status' => 'new',
            'how_hear' => fake()->randomElement(['social_media', 'referral', 'website']),
            'do_not_call' => false,
            'hot_lead' => false,
            'warm_lead' => false,
        ];
    }
}
