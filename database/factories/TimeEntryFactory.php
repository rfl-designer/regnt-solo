<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 week', '-1 hour');
        $stoppedAt = fake()->dateTimeBetween($startedAt, 'now');

        return [
            'task_id' => Task::factory(),
            'started_at' => $startedAt,
            'stopped_at' => $stoppedAt,
            'notes' => fake()->optional(0.5)->sentence(),
        ];
    }

    /**
     * Indicate that the time entry is still running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stopped_at' => null,
        ]);
    }

    /**
     * Indicate that the time entry is stopped (explicit).
     */
    public function stopped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stopped_at' => fake()->dateTimeBetween($attributes['started_at'] ?? '-30 minutes', 'now'),
        ]);
    }

    /**
     * Indicate that the time entry belongs to a specific task.
     */
    public function forTask(Task $task): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_id' => $task->id,
        ]);
    }
}
