<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(rand(3, 8)),
            'description' => fake()->optional(0.6)->paragraph(),
            'status' => fake()->randomElement(TaskStatus::cases()),
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'due_date' => fake()->optional(0.5)->dateTimeBetween('-1 week', '+2 weeks'),
            'estimated_minutes' => fake()->optional(0.4)->randomElement([15, 30, 45, 60, 90, 120, 180]),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Indicate that the task is in the inbox.
     */
    public function inbox(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::Inbox,
        ]);
    }

    /**
     * Indicate that the task is todo.
     */
    public function todo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::Todo,
        ]);
    }

    /**
     * Indicate that the task is in progress.
     */
    public function doing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::Doing,
        ]);
    }

    /**
     * Indicate that the task is done.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::Done,
            'completed_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    /**
     * Indicate that the task is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_date' => fake()->dateTimeBetween('-2 weeks', '-1 day'),
            'status' => TaskStatus::Todo,
        ]);
    }

    /**
     * Indicate that the task belongs to a project.
     */
    public function withProject(): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => Project::factory(),
        ]);
    }
}
