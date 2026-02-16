<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'content' => fake()->paragraphs(3, true),
            'type' => DocumentType::Note,
            'is_pinned' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the document is a PRD.
     */
    public function prd(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::Prd,
        ]);
    }

    /**
     * Indicate that the document is a specification.
     */
    public function spec(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::Spec,
        ]);
    }

    /**
     * Indicate that the document is a decision.
     */
    public function decision(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::Decision,
        ]);
    }

    /**
     * Indicate that the document is a reference.
     */
    public function reference(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::Reference,
        ]);
    }

    /**
     * Indicate that the document is pinned.
     */
    public function pinned(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_pinned' => true,
        ]);
    }

    /**
     * Indicate that the document belongs to a specific project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
        ]);
    }
}
