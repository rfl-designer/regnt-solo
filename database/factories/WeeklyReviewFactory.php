<?php

namespace Database\Factories;

use App\Models\WeeklyReview;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyReview>
 */
class WeeklyReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $weekStart = Carbon::instance(fake()->unique()->dateTimeBetween('-12 weeks', '-1 week'))->startOfWeek();

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->endOfWeek()->toDateString(),
            'notes' => null,
            'reflection' => null,
            'generated_at' => now(),
        ];
    }

    /**
     * Indicate that the review is for the current week.
     */
    public function thisWeek(): static
    {
        return $this->state(fn (array $attributes) => [
            'week_start' => now()->startOfWeek()->toDateString(),
            'week_end' => now()->endOfWeek()->toDateString(),
        ]);
    }

    /**
     * Indicate that the review is for last week.
     */
    public function lastWeek(): static
    {
        return $this->state(fn (array $attributes) => [
            'week_start' => now()->subWeek()->startOfWeek()->toDateString(),
            'week_end' => now()->subWeek()->endOfWeek()->toDateString(),
        ]);
    }

    /**
     * Indicate that the review has a reflection.
     */
    public function withReflection(): static
    {
        return $this->state(fn (array $attributes) => [
            'reflection' => fake()->paragraph(),
        ]);
    }
}
