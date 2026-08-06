<?php

namespace Database\Factories;

use App\Enums\ClientChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => fake()->hexColor(),
            'update_day' => fake()->numberBetween(1, 7),
            'update_time' => fake()->time('H:i:s'),
            'channel' => fake()->randomElement(ClientChannel::cases())->value,
            'response_agreement' => fake()->optional()->sentence(),
            'notes' => fake()->optional()->paragraph(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the client is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
