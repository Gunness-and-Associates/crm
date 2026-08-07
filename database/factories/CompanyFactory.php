<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->company(),
            'contact_person_name' => fake()->name(),
            'contact_person_phone' => fake()->phoneNumber(),
            'primary_email' => fake()->unique()->companyEmail(),
            'industry' => fake()->randomElement(['Construction', 'Retail', 'Hospitality', 'Manufacturing']),
            'company_contact_status' => 'active',
            'do_not_call' => false,
            'pnp_program' => false,
            'resume_submitted' => false,
            'hot_lead' => false,
            'warm_lead' => false,
        ];
    }
}
