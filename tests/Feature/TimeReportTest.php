<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('time route requires authentication', function () {
    auth()->logout();

    $this->get(route('time'))
        ->assertRedirect(route('login'));
});

test('time report page renders correctly for authenticated users', function () {
    $this->get(route('time'))
        ->assertOk();
});

test('time report component renders successfully', function () {
    Livewire::test('pages::time-report')
        ->assertSuccessful()
        ->assertSee('Relatório de Tempo');
});

test('time report defaults to thisWeek period', function () {
    Livewire::test('pages::time-report')
        ->assertSet('period', 'thisWeek');
});

test('time report shows completed time entries', function () {
    $task = Task::factory()->create(['title' => 'Task com tempo']);
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
        'notes' => 'Trabalhando no feature',
    ]);

    Livewire::test('pages::time-report')
        ->assertSee('Task com tempo')
        ->assertSee('Trabalhando no feature');
});

test('time report does not show running entries', function () {
    $task = Task::factory()->create(['title' => 'Task rodando']);
    TimeEntry::factory()->running()->create([
        'task_id' => $task->id,
    ]);

    Livewire::test('pages::time-report')
        ->assertDontSee('Task rodando');
});

test('time report shows empty state when no entries exist', function () {
    Livewire::test('pages::time-report')
        ->assertSee('Nenhum registro encontrado');
});

test('time report filters by project', function () {
    $projectA = Project::factory()->create(['name' => 'Projeto Alpha']);
    $projectB = Project::factory()->create(['name' => 'Projeto Beta']);

    $taskA = Task::factory()->create(['project_id' => $projectA->id, 'title' => 'Task Alpha']);
    $taskB = Task::factory()->create(['project_id' => $projectB->id, 'title' => 'Task Beta']);

    TimeEntry::factory()->create([
        'task_id' => $taskA->id,
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
    ]);
    TimeEntry::factory()->create([
        'task_id' => $taskB->id,
        'started_at' => now()->subHours(2),
        'stopped_at' => now()->subHour(),
    ]);

    Livewire::test('pages::time-report')
        ->set('project', (string) $projectA->id)
        ->assertSee('Task Alpha')
        ->assertDontSee('Task Beta');
});

test('time report shows all projects when filter is empty', function () {
    $projectA = Project::factory()->create(['name' => 'Projeto Alpha']);
    $projectB = Project::factory()->create(['name' => 'Projeto Beta']);

    $taskA = Task::factory()->create(['project_id' => $projectA->id, 'title' => 'Task Alpha']);
    $taskB = Task::factory()->create(['project_id' => $projectB->id, 'title' => 'Task Beta']);

    TimeEntry::factory()->create([
        'task_id' => $taskA->id,
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
    ]);
    TimeEntry::factory()->create([
        'task_id' => $taskB->id,
        'started_at' => now()->subHours(2),
        'stopped_at' => now()->subHour(),
    ]);

    Livewire::test('pages::time-report')
        ->assertSee('Task Alpha')
        ->assertSee('Task Beta');
});

test('time report shows project info on entries', function () {
    $project = Project::factory()->create(['name' => 'Meu Projeto', 'emoji' => '🚀']);
    $task = Task::factory()->create(['project_id' => $project->id]);
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
    ]);

    Livewire::test('pages::time-report')
        ->assertSee('🚀')
        ->assertSee('Meu Projeto');
});

test('time report accepts period query parameter', function () {
    Livewire::test('pages::time-report', ['period' => 'today'])
        ->assertSet('period', 'today');
});

test('time report accepts thisMonth period', function () {
    Livewire::test('pages::time-report', ['period' => 'thisMonth'])
        ->assertSet('period', 'thisMonth');
});

test('time report shows total duration', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subMinutes(90),
        'stopped_at' => now(),
    ]);

    Livewire::test('pages::time-report')
        ->assertSee('1h 30m');
});

test('time report shows entry count', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->count(3)->create([
        'task_id' => $task->id,
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
    ]);

    $component = Livewire::test('pages::time-report');

    expect($component->get('entries'))->toHaveCount(3);
});

test('time report groups entries by date', function () {
    $task = Task::factory()->create();

    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
    ]);
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subDay()->subHour(),
        'stopped_at' => now()->subDay(),
    ]);

    $component = Livewire::test('pages::time-report');

    expect($component->get('entriesByDate'))->toHaveCount(2);
});

test('time report generates markdown with correct content', function () {
    $project = Project::factory()->create(['name' => 'Projeto Test']);
    $task = Task::factory()->create(['title' => 'Task Test', 'project_id' => $project->id]);
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subMinutes(60),
        'stopped_at' => now(),
        'notes' => 'Nota de teste',
    ]);

    Livewire::test('pages::time-report')
        ->call('copyMarkdown')
        ->assertDispatched('copy-to-clipboard', function (string $event, array $params): bool {
            return str_contains($params['markdown'], 'Task Test')
                && str_contains($params['markdown'], 'Projeto Test')
                && str_contains($params['markdown'], 'Nota de teste')
                && str_contains($params['markdown'], '1h');
        });
});

test('time report copy markdown dispatches event', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
    ]);

    Livewire::test('pages::time-report')
        ->call('copyMarkdown')
        ->assertDispatched('copy-to-clipboard');
});

test('time report does not show entries outside date range', function () {
    $task = Task::factory()->create(['title' => 'Task antiga']);
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subMonth()->subDay(),
        'stopped_at' => now()->subMonth(),
    ]);

    Livewire::test('pages::time-report', ['period' => 'today'])
        ->assertDontSee('Task antiga');
});

test('time report shows subtotal label', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
    ]);

    Livewire::test('pages::time-report')
        ->assertSee('Subtotal')
        ->assertSee('TOTAL');
});

test('time report listens to timer-stopped event', function () {
    Livewire::test('pages::time-report')
        ->dispatch('timer-stopped')
        ->assertSuccessful();
});

test('time report shows copy markdown button', function () {
    Livewire::test('pages::time-report')
        ->assertSee('Copiar como Markdown');
});

test('time report lists active projects in filter', function () {
    $active = Project::factory()->create(['name' => 'Projeto Ativo']);
    Project::factory()->archived()->create(['name' => 'Projeto Arquivado']);

    $component = Livewire::test('pages::time-report');

    $projects = $component->get('projects');

    expect($projects->pluck('name')->toArray())->toContain('Projeto Ativo')
        ->and($projects->pluck('name')->toArray())->not->toContain('Projeto Arquivado');
});

test('time report does not override range when period is custom', function () {
    Livewire::test('pages::time-report')
        ->set('period', 'custom')
        ->assertSet('period', 'custom');
});

test('time report markdown escapes pipe characters in notes', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
        'notes' => 'Fix | bug | test',
    ]);

    Livewire::test('pages::time-report')
        ->call('copyMarkdown')
        ->assertDispatched('copy-to-clipboard', function (string $event, array $params): bool {
            return str_contains($params['markdown'], 'Fix \\| bug \\| test');
        });
});
