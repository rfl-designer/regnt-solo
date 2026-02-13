<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('projects route requires authentication', function () {
    auth()->logout();

    $this->get(route('projects'))
        ->assertRedirect(route('login'));
});

test('projects page renders for authenticated users', function () {
    $this->get(route('projects'))
        ->assertOk();
});

test('projects component renders successfully and shows heading', function () {
    Livewire::test('pages::projects')
        ->assertSuccessful()
        ->assertSee('Projetos');
});

test('projects lists active projects by default', function () {
    $active = Project::factory()->create(['name' => 'Projeto Ativo']);
    Project::factory()->paused()->create(['name' => 'Projeto Pausado']);
    Project::factory()->archived()->create(['name' => 'Projeto Arquivado']);

    Livewire::test('pages::projects')
        ->assertSet('statusFilter', 'active')
        ->assertSee('Projeto Ativo')
        ->assertDontSee('Projeto Pausado')
        ->assertDontSee('Projeto Arquivado');
});

test('projects filters by paused status', function () {
    Project::factory()->create(['name' => 'Projeto Ativo']);
    Project::factory()->paused()->create(['name' => 'Projeto Pausado']);

    Livewire::test('pages::projects')
        ->set('statusFilter', 'paused')
        ->assertSee('Projeto Pausado')
        ->assertDontSee('Projeto Ativo');
});

test('projects filters by archived status', function () {
    Project::factory()->create(['name' => 'Projeto Ativo']);
    Project::factory()->archived()->create(['name' => 'Projeto Arquivado']);

    Livewire::test('pages::projects')
        ->set('statusFilter', 'archived')
        ->assertSee('Projeto Arquivado')
        ->assertDontSee('Projeto Ativo');
});

test('projects shows project card with emoji, name, and status badge', function () {
    Project::factory()->create([
        'name' => 'Meu Projeto',
        'emoji' => '🚀',
        'status' => ProjectStatus::Active,
    ]);

    Livewire::test('pages::projects')
        ->assertSee('🚀')
        ->assertSee('Meu Projeto')
        ->assertSee('Ativo');
});

test('projects shows active tasks count', function () {
    $project = Project::factory()->create();
    Task::factory()->todo()->count(3)->create(['project_id' => $project->id]);
    Task::factory()->done()->count(2)->create(['project_id' => $project->id]);

    Livewire::test('pages::projects')
        ->assertSee('3 tasks ativas');
});

test('projects openProject redirects to project detail', function () {
    $project = Project::factory()->create(['slug' => 'meu-projeto']);

    Livewire::test('pages::projects')
        ->call('openProject', 'meu-projeto')
        ->assertRedirect(route('project.detail', 'meu-projeto'));
});

test('projects shows empty state when no projects', function () {
    Livewire::test('pages::projects')
        ->assertSee('Nenhum projeto encontrado');
});

test('projects listens to project-created event', function () {
    Livewire::test('pages::projects')
        ->dispatch('project-created')
        ->assertSuccessful();
});

test('projects listens to project-updated event', function () {
    Livewire::test('pages::projects')
        ->dispatch('project-updated')
        ->assertSuccessful();
});

test('projects shows novo projeto button', function () {
    Livewire::test('pages::projects')
        ->assertSee('Novo Projeto');
});

test('projects shows single active task label correctly', function () {
    $project = Project::factory()->create();
    Task::factory()->todo()->create(['project_id' => $project->id]);

    Livewire::test('pages::projects')
        ->assertSee('1 task ativa');
});
