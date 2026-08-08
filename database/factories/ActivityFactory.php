<?php

namespace Database\Factories;

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\ServiceClass;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ActivityType::Task,
            'title' => fake()->sentence(4),
            'status' => ActivityStatus::Inbox,
            'priority' => ActivityPriority::Medium,
            'service_class' => ServiceClass::Standard,
            'due_date' => null,
            'estimated_minutes' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the activity is an epic.
     */
    public function epic(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ActivityType::Epic,
            'slug' => Str::slug($attributes['title'] ?? fake()->sentence(3)),
            'status' => ActivityStatus::Backlog,
        ]);
    }

    /**
     * Indicate that the activity is a roadmap issue.
     */
    public function issue(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ActivityType::Issue,
        ]);
    }

    /**
     * Indicate that the activity is a personal task.
     */
    public function task(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ActivityType::Task,
        ]);
    }

    /**
     * Indicate that the activity is a draft (idea, no board status).
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ActivityType::Draft,
            'status' => null,
        ]);
    }

    /**
     * Indicate that the draft has been shaped enough to be promoted
     * (issue #148): Dor, Apetite and Esboço — the three required sections.
     * The project is left to the caller, since it is the fourth requirement
     * and most tests are about exactly that.
     */
    public function shaped(int $appetiteDays = 7): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => 'Perco tempo toda semana refazendo isso à mão.',
            'appetite_days' => $appetiteDays,
            'spec' => $this->generateSpec($attributes['title'] ?? fake()->sentence(3)),
        ]);
    }

    /**
     * Indicate that the activity has a spec.
     */
    public function withSpec(): static
    {
        return $this->state(fn (array $attributes) => [
            'spec' => $this->generateSpec($attributes['title'] ?? fake()->sentence(3)),
        ]);
    }

    /**
     * Indicate that the activity is done.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityStatus::Done,
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the activity is in doing status.
     */
    public function doing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityStatus::Doing,
        ]);
    }

    /**
     * Indicate that the activity is in todo status.
     */
    public function todo(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityStatus::Todo,
        ]);
    }

    /**
     * Indicate that the activity is in backlog status.
     */
    public function backlog(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityStatus::Backlog,
        ]);
    }

    /**
     * Indicate that the activity is awaiting client approval, with
     * waiting_for already set (bypassing the auto-fill guard for tests
     * that don't care about the client-resolution path).
     */
    public function awaitingApproval(?string $waitingFor = 'Cliente'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityStatus::AwaitingApproval,
            'waiting_for' => $waitingFor,
            'waiting_since' => now(),
        ]);
    }

    /**
     * Indicate that the activity is in the internal wait (Esperando),
     * with waiting_for already set.
     */
    public function waiting(string $waitingFor = 'Designer'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityStatus::Waiting,
            'waiting_for' => $waitingFor,
            'waiting_since' => now(),
        ]);
    }

    /**
     * Indicate that the activity is awaiting client validation, with
     * waiting_for already set.
     */
    public function awaitingValidation(?string $waitingFor = 'Cliente'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ActivityStatus::AwaitingValidation,
            'waiting_for' => $waitingFor,
            'waiting_since' => now(),
        ]);
    }

    /**
     * Indicate that the activity is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => now()->subDays(3),
            'status' => ActivityStatus::Todo,
        ]);
    }

    /**
     * Indicate that the activity has urgent priority.
     */
    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => ActivityPriority::Urgent,
        ]);
    }

    /**
     * Indicate that the activity has high priority.
     */
    public function high(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => ActivityPriority::High,
        ]);
    }

    /**
     * Indicate that the activity has low priority.
     */
    public function low(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => ActivityPriority::Low,
        ]);
    }

    /**
     * Indicate that the activity is classified as Emergência, with the
     * mandatory motivo already filled in.
     *
     * This state is the only way a factory produces an Emergência: there is
     * deliberately no global repair that quietly fills a missing motivo,
     * because that would make an incomplete fixture — and a caller that
     * forgot the new field — look valid.
     */
    public function emergency(string $reason = 'Produção fora do ar.'): static
    {
        return $this->state(fn (array $attributes) => [
            'service_class' => ServiceClass::Emergency,
            'emergency_reason' => $reason,
        ]);
    }

    /**
     * Indicate that the activity is classified as Data fixa (requires a due date).
     */
    public function fixedDate(?string $date = null): static
    {
        return $this->state(fn (array $attributes) => [
            'service_class' => ServiceClass::FixedDate,
            'due_date' => $date ?? fake()->dateTimeBetween('now', '+30 days'),
        ]);
    }

    /**
     * Indicate that the activity is classified as Intangível.
     */
    public function intangible(): static
    {
        return $this->state(fn (array $attributes) => [
            'service_class' => ServiceClass::Intangible,
        ]);
    }

    /**
     * Indicate that the activity has a due date.
     */
    public function withDueDate(?string $date = null): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => $date ?? fake()->dateTimeBetween('now', '+30 days'),
        ]);
    }

    /**
     * Indicate that the activity has an estimated duration.
     */
    public function withEstimate(int $minutes = 60): static
    {
        return $this->state(fn (array $attributes) => [
            'estimated_minutes' => $minutes,
        ]);
    }

    /**
     * Indicate that the activity is a session task with AI-generated prompt and optional result.
     */
    public function session(): static
    {
        return $this->state(fn (array $attributes) => [
            'session_prompt' => fake()->paragraph(),
            'session_result' => fake()->optional(0.7)->paragraph(),
        ]);
    }

    /**
     * Indicate that the activity hangs under a parent activity.
     */
    public function forParent(Activity $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
            'project_id' => $parent->project_id,
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
