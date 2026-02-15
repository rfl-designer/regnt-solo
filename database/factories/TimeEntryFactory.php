<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
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
        $startedAt = fake()->dateTimeBetween('-7 days', 'now');
        $stoppedAt = (clone $startedAt);
        $stoppedAt->modify('+'.rand(15, 120).' minutes');

        return [
            'task_id' => Task::factory(),
            'started_at' => $startedAt,
            'stopped_at' => $stoppedAt,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the time entry is still running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'started_at' => now()->subMinutes(rand(5, 60)),
            'stopped_at' => null,
        ]);
    }

    /**
     * Indicate that the time entry is a focus session.
     */
    public function focus(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_focus_session' => true,
        ]);
    }

    /**
     * Indicate that the time entry is a focus session with a rating.
     */
    public function focusWithRating(int $rating = 4): static
    {
        return $this->state(fn (array $attributes) => [
            'is_focus_session' => true,
            'focus_rating' => $rating,
        ]);
    }
}
