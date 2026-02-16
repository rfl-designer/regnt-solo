<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stakeholder>
 */
class StakeholderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'access_token' => (string) Str::uuid(),
            'last_accessed_at' => null,
        ];
    }

    /**
     * Indicate that the stakeholder has recently accessed.
     */
    public function recentlyAccessed(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_accessed_at' => now(),
        ]);
    }

    /**
     * Indicate that the stakeholder has never accessed.
     */
    public function neverAccessed(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_accessed_at' => null,
        ]);
    }
}
