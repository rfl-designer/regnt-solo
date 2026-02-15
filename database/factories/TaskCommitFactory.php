<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskCommit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskCommit>
 */
class TaskCommitFactory extends Factory
{
    protected $model = TaskCommit::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'hash' => fake()->sha1(),
            'message' => fake()->sentence(),
            'files_changed' => fake()->numberBetween(1, 20),
            'insertions' => fake()->numberBetween(0, 500),
            'deletions' => fake()->numberBetween(0, 200),
            'committed_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ];
    }
}
