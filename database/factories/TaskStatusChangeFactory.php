<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskStatusChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskStatusChange>
 */
class TaskStatusChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'from_status' => fake()->randomElement(TaskStatus::cases()),
            'to_status' => fake()->randomElement(TaskStatus::cases()),
            'changed_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /**
     * Indicate an initial status change (task creation, from_status = null).
     */
    public function initial(TaskStatus $status = TaskStatus::Inbox): static
    {
        return $this->state(fn (array $attributes) => [
            'from_status' => null,
            'to_status' => $status,
        ]);
    }
}
