<?php

namespace Database\Factories;

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormSubmission>
 */
class FormSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_template_id' => FormTemplate::factory(),
            'current_version_id' => null,
        ];
    }
}
