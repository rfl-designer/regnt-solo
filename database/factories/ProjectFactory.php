<?php

namespace Database\Factories;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
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
            'status' => ProjectStatus::Active,
            'priority' => ProjectPriority::Medium,
            'description' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the project is in backlog.
     */
    public function backlog(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Backlog,
        ]);
    }

    /**
     * Indicate that the project is paused.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Paused,
        ]);
    }

    /**
     * Indicate that the project is done.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Done,
        ]);
    }

    /**
     * Indicate that the project is in maintenance.
     */
    public function maintenance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Maintenance,
        ]);
    }

    /**
     * Indicate that the project is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Archived,
        ]);
    }

    /**
     * Indicate that the project has urgent priority.
     */
    public function urgentPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => ProjectPriority::Urgent,
        ]);
    }

    /**
     * Indicate that the project has high priority.
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => ProjectPriority::High,
        ]);
    }

    /**
     * Indicate that the project has low priority.
     */
    public function lowPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => ProjectPriority::Low,
        ]);
    }
}
