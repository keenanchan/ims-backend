<?php

namespace Database\Factories;

use App\Enums\SyncConflictStatus;
use App\Models\FormSubmission;
use App\Models\SyncConflict;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncConflict>
 */
class SyncConflictFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_submission_id' => FormSubmission::factory(),
            'user_id' => User::factory(),
            'base_version_number' => 1,
            'submitted_form_name' => fake()->words(3, true).' Form',
            'submitted_content' => [
                'first_name' => fake()->firstName(),
                'last_name' => fake()->lastName(),
            ],
            'status' => SyncConflictStatus::Open,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => SyncConflictStatus::Resolved]);
    }

    public function discarded(): static
    {
        return $this->state(fn () => ['status' => SyncConflictStatus::Discarded]);
    }
}
