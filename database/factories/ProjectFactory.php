<?php

namespace Database\Factories;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(rand(2, 4), true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => fake()->hexColor(),
            'emoji' => fake()->randomElement(['📋', '🚀', '💡', '🎯', '📦', '🔧', '📊', '🎨']),
            'status' => fake()->randomElement(ProjectStatus::cases()),
            'priority' => fake()->randomElement(ProjectPriority::cases()),
            'description' => fake()->optional(0.7)->sentence(),
        ];
    }

    /**
     * Indicate that the project is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Active,
        ]);
    }

    /**
     * Indicate that the project is paused.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Paused,
        ]);
    }

    /**
     * Indicate that the project is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Archived,
        ]);
    }

    /**
     * Indicate that the project has high priority.
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => ProjectPriority::High,
        ]);
    }

    /**
     * Indicate that the project has medium priority.
     */
    public function mediumPriority(): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => ProjectPriority::Medium,
        ]);
    }

    /**
     * Indicate that the project has low priority.
     */
    public function lowPriority(): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => ProjectPriority::Low,
        ]);
    }
}
