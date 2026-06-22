<?php

namespace Database\Factories;

use App\Enums\FeaturePriority;
use App\Enums\FeatureStatus;
use App\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'priority' => FeaturePriority::Medium,
            'status' => FeatureStatus::Draft,
            'due_date' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the feature has a spec.
     */
    public function withSpec(): static
    {
        return $this->state(fn (array $attributes) => [
            'spec' => $this->generateSpec($attributes['title']),
        ]);
    }

    /**
     * Indicate that the feature has urgent priority.
     */
    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => FeaturePriority::Urgent,
        ]);
    }

    /**
     * Indicate that the feature has high priority.
     */
    public function high(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => FeaturePriority::High,
        ]);
    }

    /**
     * Indicate that the feature has low priority.
     */
    public function low(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => FeaturePriority::Low,
        ]);
    }

    /**
     * Indicate that the feature has a due date.
     */
    public function withDueDate(?string $date = null): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => $date ?? fake()->dateTimeBetween('now', '+30 days'),
        ]);
    }

    /**
     * Indicate that the feature is in backlog status.
     */
    public function backlog(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FeatureStatus::Backlog,
        ]);
    }

    /**
     * Indicate that the feature is in todo status.
     */
    public function todo(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FeatureStatus::Todo,
        ]);
    }

    /**
     * Indicate that the feature is in doing status.
     */
    public function doing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FeatureStatus::Doing,
        ]);
    }

    /**
     * Indicate that the feature is done.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FeatureStatus::Done,
        ]);
    }

    /**
     * Generate a realistic spec in User Story format.
     */
    private function generateSpec(string $title): string
    {
        $persona = fake()->randomElement(['desenvolvedor', 'usuário', 'administrador', 'gerente']);
        $benefit = fake()->sentence();

        return <<<SPEC
## User Story
Como {$persona}, quero {$title} para {$benefit}

## Contexto
{$this->faker->paragraph()}

## Critérios de Aceitação
- [ ] {$this->faker->sentence()}
- [ ] {$this->faker->sentence()}
- [ ] {$this->faker->sentence()}

## Notas Técnicas
{$this->faker->paragraph()}
SPEC;
    }
}
