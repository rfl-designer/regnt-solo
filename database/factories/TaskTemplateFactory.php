<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'description' => fake()->optional()->paragraph(),
            'default_priority' => fake()->randomElement(TaskPriority::cases()),
            'default_estimated_minutes' => fake()->optional()->numberBetween(15, 180),
            'icon' => fake()->optional()->randomElement(['clipboard', 'code-bracket', 'bug-ant', 'rocket-launch', 'wrench']),
            'color' => fake()->optional()->randomElement(['blue', 'green', 'orange', 'purple', 'red']),
            'is_system' => false,
        ];
    }

    /**
     * Indicate the template is a system template.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_system' => true,
        ]);
    }

    /**
     * Create a code review template.
     */
    public function codeReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Code Review',
            'slug' => 'code-review',
            'description' => "## Checklist\n\n- [ ] Verificar lógica de negócio\n- [ ] Revisar tratamento de erros\n- [ ] Checar testes\n- [ ] Validar performance\n- [ ] Aprovar ou solicitar mudanças",
            'default_priority' => TaskPriority::High,
            'default_estimated_minutes' => 30,
            'icon' => 'eye',
            'color' => 'blue',
            'is_system' => true,
        ]);
    }

    /**
     * Create a deploy checklist template.
     */
    public function deployChecklist(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Deploy Checklist',
            'slug' => 'deploy-checklist',
            'description' => "## Pré-Deploy\n\n- [ ] Testes passando\n- [ ] Migrations revisadas\n- [ ] Variáveis de ambiente atualizadas\n- [ ] Backup do banco\n\n## Deploy\n\n- [ ] Rodar migrations\n- [ ] Limpar cache\n- [ ] Verificar logs\n\n## Pós-Deploy\n\n- [ ] Testar funcionalidades críticas\n- [ ] Monitorar erros",
            'default_priority' => TaskPriority::Urgent,
            'default_estimated_minutes' => 45,
            'icon' => 'rocket-launch',
            'color' => 'red',
            'is_system' => true,
        ]);
    }

    /**
     * Create a bug investigation template.
     */
    public function bugInvestigation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Bug Investigation',
            'slug' => 'bug-investigation',
            'description' => "## Reprodução\n\n- [ ] Consegui reproduzir o bug\n- [ ] Ambiente: \n- [ ] Passos para reproduzir:\n\n## Investigação\n\n- [ ] Analisar logs\n- [ ] Identificar causa raiz\n- [ ] Verificar se há issues relacionadas\n\n## Solução\n\n- [ ] Propor correção\n- [ ] Escrever testes\n- [ ] Documentar solução",
            'default_priority' => TaskPriority::High,
            'default_estimated_minutes' => 60,
            'icon' => 'bug-ant',
            'color' => 'orange',
            'is_system' => true,
        ]);
    }

    /**
     * Create a daily standup template.
     */
    public function dailyStandup(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Daily Standup',
            'slug' => 'daily-standup',
            'description' => "## O que fiz ontem?\n\n- \n\n## O que vou fazer hoje?\n\n- \n\n## Bloqueios?\n\n- ",
            'default_priority' => TaskPriority::Medium,
            'default_estimated_minutes' => 15,
            'icon' => 'chat-bubble-left-right',
            'color' => 'green',
            'is_system' => true,
        ]);
    }
}
