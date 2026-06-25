<?php

namespace Database\Factories;

use App\Enums\StakeholderIssueStatus;
use App\Models\Project;
use App\Models\Stakeholder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StakeholderIssue>
 */
class StakeholderIssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $project = Project::factory();

        return [
            'project_id' => $project,
            'stakeholder_id' => Stakeholder::factory()->for($project),
            'activity_id' => null,
            'comment' => fake()->paragraph(),
            'status' => StakeholderIssueStatus::Unread,
            'converted_at' => null,
        ];
    }

    /**
     * Indicate that the issue should be reviewed as a feature.
     */
    public function toFeature(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StakeholderIssueStatus::ToFeature,
        ]);
    }

    /**
     * Indicate that the issue has already been converted into a feature.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StakeholderIssueStatus::Feature,
            'converted_at' => now(),
        ]);
    }

    /**
     * Indicate that the issue has been archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StakeholderIssueStatus::Archived,
        ]);
    }
}
