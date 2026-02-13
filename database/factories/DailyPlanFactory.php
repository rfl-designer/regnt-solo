<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DailyPlan>
 */
class DailyPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->dateTimeBetween('-1 month', '+1 month'),
            'notes' => null,
        ];
    }

    /**
     * Indicate that the daily plan is for today.
     */
    public function today(): static
    {
        return $this->state(fn (array $attributes): array => [
            'date' => now()->toDateString(),
        ]);
    }

    /**
     * Indicate that the daily plan is for yesterday.
     */
    public function yesterday(): static
    {
        return $this->state(fn (array $attributes): array => [
            'date' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * Indicate that the daily plan has notes.
     */
    public function withNotes(): static
    {
        return $this->state(fn (array $attributes): array => [
            'notes' => fake()->paragraph(),
        ]);
    }
}
