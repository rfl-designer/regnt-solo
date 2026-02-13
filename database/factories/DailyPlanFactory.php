<?php

namespace Database\Factories;

use App\Models\DailyPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyPlan>
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
            'date' => fake()->unique()->date(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the daily plan is for today.
     */
    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => now()->toDateString(),
        ]);
    }
}
