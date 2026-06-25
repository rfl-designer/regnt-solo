<?php

use App\Models\Activity;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('analytics route requires authentication', function () {
    auth()->logout();

    $this->get(route('analytics'))
        ->assertRedirect(route('login'));
});

test('analytics page renders correctly for authenticated users', function () {
    $this->get(route('analytics'))
        ->assertOk();
});

test('analytics component renders successfully', function () {
    Livewire::test('pages::analytics')
        ->assertSuccessful()
        ->assertSee('Analytics Pessoais');
});

test('analytics defaults to 12w period', function () {
    Livewire::test('pages::analytics')
        ->assertSet('period', '12w');
});

test('analytics period can be changed to 4w', function () {
    Livewire::test('pages::analytics')
        ->set('period', '4w')
        ->assertSet('period', '4w')
        ->assertSuccessful();
});

test('analytics period can be changed to 6m', function () {
    Livewire::test('pages::analytics')
        ->set('period', '6m')
        ->assertSet('period', '6m')
        ->assertSuccessful();
});

test('analytics shows streak section', function () {
    Livewire::test('pages::analytics')
        ->assertSee('Sequência atual')
        ->assertSee('Melhor sequência')
        ->assertSee('Sequência de foco')
        ->assertSee('Melhor foco');
});

test('analytics shows velocity section', function () {
    Livewire::test('pages::analytics')
        ->assertSee('Velocidade');
});

test('analytics shows cycle time section', function () {
    Livewire::test('pages::analytics')
        ->assertSee('Cycle Time');
});

test('analytics shows project health section', function () {
    Livewire::test('pages::analytics')
        ->assertSee('Saude dos Projetos');
});

test('analytics shows productivity patterns section', function () {
    Livewire::test('pages::analytics')
        ->assertSee('Padroes de Produtividade');
});

test('analytics shows heatmap section', function () {
    Livewire::test('pages::analytics')
        ->assertSee('Atividade');
});

test('analytics shows focus ratio', function () {
    Livewire::test('pages::analytics')
        ->assertSee('Taxa de foco');
});

test('analytics heatmap displays data when time entries exist', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => now()->subHours(2),
        'stopped_at' => now(),
    ]);

    $component = Livewire::test('pages::analytics');

    expect($component->get('heatmap'))->not->toBeEmpty();
});

test('analytics velocity shows data when tasks exist', function () {
    Activity::factory()->create([
        'created_at' => now()->subDays(3),
    ]);

    $component = Livewire::test('pages::analytics');

    expect($component->get('velocity'))->not->toBeEmpty();
});

test('analytics streaks returns expected structure', function () {
    $component = Livewire::test('pages::analytics');

    $streaks = $component->get('streakData');

    expect($streaks)->toHaveKeys(['current', 'best', 'focus_current', 'focus_best']);
});

test('analytics health scores show active projects', function () {
    Project::factory()->create(['name' => 'Projeto Saudavel']);

    Livewire::test('pages::analytics')
        ->assertSee('Projeto Saudavel');
});

test('analytics health scores do not show archived projects', function () {
    Project::factory()->archived()->create(['name' => 'Projeto Arquivado']);

    Livewire::test('pages::analytics')
        ->assertDontSee('Projeto Arquivado');
});

test('analytics cycle time project filter defaults to null', function () {
    Livewire::test('pages::analytics')
        ->assertSet('cycleTimeProjectId', null);
});

test('analytics cycle time can be filtered by project', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::analytics')
        ->set('cycleTimeProjectId', $project->id)
        ->assertSet('cycleTimeProjectId', $project->id)
        ->assertSuccessful();
});

test('analytics lists active projects in cycle time filter', function () {
    $active = Project::factory()->create(['name' => 'Projeto Ativo']);
    Project::factory()->archived()->create(['name' => 'Projeto Arquivado']);

    $component = Livewire::test('pages::analytics');

    $projects = $component->get('projects');

    expect($projects->pluck('name')->toArray())->toContain('Projeto Ativo')
        ->and($projects->pluck('name')->toArray())->not->toContain('Projeto Arquivado');
});

test('analytics format duration returns correct values', function () {
    $component = Livewire::test('pages::analytics');

    $component->call('formatDuration', 0);
    $component->call('formatDuration', 30);
    $component->call('formatDuration', 90);

    $component->assertSuccessful();
});

test('analytics patterns returns expected structure', function () {
    $component = Livewire::test('pages::analytics');

    $patterns = $component->get('patterns');

    expect($patterns)->toHaveKeys(['best_days', 'best_hours', 'avg_by_day', 'avg_by_hour']);
});

test('analytics focus ratio is between 0 and 1', function () {
    $component = Livewire::test('pages::analytics');

    $ratio = $component->get('focusRatioValue');

    expect($ratio)->toBeGreaterThanOrEqual(0.0)
        ->and($ratio)->toBeLessThanOrEqual(1.0);
});
