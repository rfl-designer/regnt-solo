<?php

use App\Models\Activity;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('project detail shows docs tab', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Docs');
});

test('project detail docs tab shows project documents', function () {
    $project = Project::factory()->create();
    Document::factory()->forProject($project)->create(['title' => 'Project PRD']);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'docs')
        ->assertSee('Project PRD');
});

test('project detail docs tab shows empty state', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'docs')
        ->assertSee('Nenhum documento neste projeto');
});

test('task modal shows project documents when task has project', function () {
    $project = Project::factory()->create();
    Document::factory()->forProject($project)->create(['title' => 'API Spec']);
    $task = Activity::factory()->create(['project_id' => $project->id, 'session_prompt' => 'Test prompt']);

    Livewire::test('task-modal')
        ->call('openTask', $task->id)
        ->assertSee('API Spec')
        ->assertSee('Documentos do Projeto');
});

test('task modal does not show documents section when task has no project', function () {
    $task = Activity::factory()->create(['project_id' => null]);

    Livewire::test('task-modal')
        ->call('openTask', $task->id)
        ->assertDontSee('Documentos do Projeto');
});

test('task modal renders session prompt as markdown when done', function () {
    $task = Activity::factory()->done()->create([
        'session_prompt' => '**bold prompt**',
        'session_result' => '**bold result**',
    ]);

    Livewire::test('task-modal')
        ->call('openTask', $task->id)
        ->assertSeeHtml('<strong>bold prompt</strong>')
        ->assertSeeHtml('<strong>bold result</strong>');
});

test('task modal shows session prompt field when not done', function () {
    $task = Activity::factory()->doing()->create([
        'session_prompt' => 'My prompt',
    ]);

    Livewire::test('task-modal')
        ->call('openTask', $task->id)
        ->assertSee('Instruções AI')
        ->assertSee('My prompt');
});
