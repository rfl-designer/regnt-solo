<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityCommit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityCommit>
 */
class ActivityCommitFactory extends Factory
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
            'hash' => fake()->sha1(),
            'message' => fake()->sentence(),
            'files_changed' => fake()->numberBetween(1, 20),
            'insertions' => fake()->numberBetween(0, 500),
            'deletions' => fake()->numberBetween(0, 200),
            'committed_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ];
    }
}
