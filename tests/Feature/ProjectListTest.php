<?php

use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Project;
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

test('projects kanban shows all status columns', function () {
    Livewire::test('pages::projects')
        ->assertSee('Ativo')
        ->assertSee('Pausado')
        ->assertSee('Arquivado');
});

test('projects kanban shows projects in correct columns', function () {
    Project::factory()->create(['name' => 'Projeto Ativo']);
    Project::factory()->paused()->create(['name' => 'Projeto Pausado']);
    Project::factory()->archived()->create(['name' => 'Projeto Arquivado']);

    Livewire::test('pages::projects')
        ->assertSee('Projeto Ativo')
        ->assertSee('Projeto Pausado')
        ->assertSee('Projeto Arquivado');
});

test('projects kanban can drag project to change status', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);

    Livewire::test('pages::projects')
        ->call('handleSort', $project->id, 0, 'paused')
        ->assertDispatched('project-updated');

    expect($project->fresh()->status)->toBe(ProjectStatus::Paused);
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
    Activity::factory()->todo()->count(3)->create(['project_id' => $project->id]);
    Activity::factory()->done()->count(2)->create(['project_id' => $project->id]);

    Livewire::test('pages::projects')
        ->assertSee('3 tasks');
});

test('projects openProject redirects to project detail', function () {
    $project = Project::factory()->create(['slug' => 'meu-projeto']);

    Livewire::test('pages::projects')
        ->call('openProject', 'meu-projeto')
        ->assertRedirect(route('project.detail', 'meu-projeto'));
});

test('projects shows empty state when no projects', function () {
    Livewire::test('pages::projects')
        ->assertSee('Nenhum projeto ativo')
        ->assertSee('Nenhum projeto pausado')
        ->assertSee('Nenhum projeto arquivado');
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
    Activity::factory()->todo()->create(['project_id' => $project->id]);

    Livewire::test('pages::projects')
        ->assertSee('1 task');
});
