<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
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
            'title' => fake()->sentence(4),
            'status' => TaskStatus::Inbox,
            'priority' => TaskPriority::Medium,
            'due_date' => null,
            'estimated_minutes' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the task is done.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the task is in doing status.
     */
    public function doing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Doing,
        ]);
    }

    /**
     * Indicate that the task is in todo status.
     */
    public function todo(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Todo,
        ]);
    }

    /**
     * Indicate that the task is in backlog status.
     */
    public function backlog(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Backlog,
        ]);
    }

    /**
     * Indicate that the task is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => now()->subDays(3),
            'status' => TaskStatus::Todo,
        ]);
    }

    /**
     * Indicate that the task has urgent priority.
     */
    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => TaskPriority::Urgent,
        ]);
    }

    /**
     * Indicate that the task has a due date.
     */
    public function withDueDate(?string $date = null): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => $date ?? fake()->dateTimeBetween('now', '+30 days'),
        ]);
    }

    /**
     * Indicate that the task has an estimated duration.
     */
    public function withEstimate(int $minutes = 60): static
    {
        return $this->state(fn (array $attributes) => [
            'estimated_minutes' => $minutes,
        ]);
    }

    /**
     * Indicate that the task is a session task with AI-generated prompt and optional result.
     */
    public function session(): static
    {
        return $this->state(fn (array $attributes) => [
            'session_prompt' => fake()->paragraph(),
            'session_result' => fake()->optional(0.7)->paragraph(),
        ]);
    }

    /**
     * Indicate that the task belongs to a feature.
     */
    public function forFeature(\App\Models\Feature $feature): static
    {
        return $this->state(fn (array $attributes) => [
            'feature_id' => $feature->id,
            'project_id' => $feature->project_id,
        ]);
    }
}
