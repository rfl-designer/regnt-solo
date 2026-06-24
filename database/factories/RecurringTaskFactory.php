<?php

namespace Database\Factories;

use App\Enums\ActivityPriority;
use App\Enums\RecurrenceFrequency;
use App\Models\Project;
use App\Models\RecurringTask;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringTask>
 */
class RecurringTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $frequency = fake()->randomElement(RecurrenceFrequency::cases());

        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'frequency' => $frequency,
            'day_of_week' => $frequency === RecurrenceFrequency::Weekly ? fake()->numberBetween(0, 6) : null,
            'day_of_month' => $frequency === RecurrenceFrequency::Monthly ? fake()->numberBetween(1, 28) : null,
            'priority' => fake()->randomElement(ActivityPriority::cases()),
            'next_run' => Carbon::today()->addDays(fake()->numberBetween(0, 7)),
            'is_active' => true,
            'estimated_minutes' => fake()->optional()->numberBetween(15, 120),
        ];
    }

    /**
     * Indicate the recurring task belongs to a project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => $project->id,
        ]);
    }

    /**
     * Indicate the recurring task is daily.
     */
    public function daily(): static
    {
        return $this->state(fn (array $attributes): array => [
            'frequency' => RecurrenceFrequency::Daily,
            'day_of_week' => null,
            'day_of_month' => null,
        ]);
    }

    /**
     * Indicate the recurring task is for weekdays only.
     */
    public function weekdays(): static
    {
        return $this->state(fn (array $attributes): array => [
            'frequency' => RecurrenceFrequency::Weekdays,
            'day_of_week' => null,
            'day_of_month' => null,
        ]);
    }

    /**
     * Indicate the recurring task is weekly.
     */
    public function weekly(int $dayOfWeek = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'frequency' => RecurrenceFrequency::Weekly,
            'day_of_week' => $dayOfWeek,
            'day_of_month' => null,
        ]);
    }

    /**
     * Indicate the recurring task is monthly.
     */
    public function monthly(int $dayOfMonth = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'frequency' => RecurrenceFrequency::Monthly,
            'day_of_week' => null,
            'day_of_month' => $dayOfMonth,
        ]);
    }

    /**
     * Indicate the recurring task is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate the recurring task is due today.
     */
    public function dueToday(): static
    {
        return $this->state(fn (array $attributes): array => [
            'next_run' => Carbon::today(),
        ]);
    }

    /**
     * Indicate the recurring task is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'next_run' => Carbon::today()->subDays(fake()->numberBetween(1, 5)),
        ]);
    }
}
