<?php

namespace Database\Factories;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityStatusChange>
 */
class ActivityStatusChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'from_status' => fake()->randomElement(ActivityStatus::cases()),
            'to_status' => fake()->randomElement(ActivityStatus::cases()),
            'changed_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /**
     * Indicate an initial status change (activity creation, from_status = null).
     */
    public function initial(ActivityStatus $status = ActivityStatus::Inbox): static
    {
        return $this->state(fn (array $attributes) => [
            'from_status' => null,
            'to_status' => $status,
        ]);
    }
}
