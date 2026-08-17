<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Welcome',
            'module_key' => 'leads',
            'subject' => 'Welcome, {{full_name}}',
            'body_html' => '<p>Hi {{full_name}}, thanks for your interest.</p>',
        ];
    }
}
