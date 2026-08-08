<?php

namespace Database\Factories;

use App\Models\MorningRitual;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MorningRitual>
 */
class MorningRitualFactory extends Factory
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
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the ritual record is for today.
     */
    public function today(): static
    {
        return $this->state(fn (array $attributes): array => [
            'date' => now()->toDateString(),
        ]);
    }

    /**
     * Indicate that the ritual was concluded at the given moment.
     */
    public function completedAt(string $at): static
    {
        return $this->state(fn (array $attributes): array => [
            'completed_at' => $at,
        ]);
    }
}
