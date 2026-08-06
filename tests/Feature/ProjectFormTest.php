<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('project form component renders successfully', function () {
    Livewire::test('project-form')
        ->assertSuccessful();
});

test('project form opens on open-project-form event', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->assertSet('showModal', true)
        ->assertSet('projectId', null)
        ->assertSet('name', '');
});

test('project form opens edit mode on edit-project event', function () {
    $project = Project::factory()->create([
        'name' => 'Meu Projeto',
        'emoji' => '🎯',
        'color' => '#ff0000',
        'priority' => ProjectPriority::High,
        'status' => ProjectStatus::Active,
        'description' => 'Descrição do projeto',
    ]);

    Livewire::test('project-form')
        ->dispatch('edit-project', projectId: $project->id)
        ->assertSet('showModal', true)
        ->assertSet('projectId', $project->id)
        ->assertSet('name', 'Meu Projeto')
        ->assertSet('emoji', '🎯')
        ->assertSet('color', '#ff0000')
        ->assertSet('priority', 'high')
        ->assertSet('status', 'active')
        ->assertSet('description', 'Descrição do projeto');
});

test('project form creates a new project with valid data', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', 'Novo Projeto')
        ->set('emoji', '🚀')
        ->set('color', '#3b82f6')
        ->set('priority', 'high')
        ->set('status', 'active')
        ->set('description', 'Uma descrição')
        ->call('saveProject');

    expect(Project::count())->toBe(1);

    $project = Project::first();
    expect($project)
        ->name->toBe('Novo Projeto')
        ->emoji->toBe('🚀')
        ->color->toBe('#3b82f6')
        ->priority->toBe(ProjectPriority::High)
        ->status->toBe(ProjectStatus::Active)
        ->description->toBe('Uma descrição');
});

test('project form validates name is required', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', '')
        ->call('saveProject')
        ->assertHasErrors(['name' => 'required']);
});

test('project form validates emoji is required', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', 'Projeto')
        ->set('emoji', '')
        ->call('saveProject')
        ->assertHasErrors(['emoji' => 'required']);
});

test('project form validates color is required', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', 'Projeto')
        ->set('color', '')
        ->call('saveProject')
        ->assertHasErrors(['color' => 'required']);
});

test('project form validates name max length', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', str_repeat('a', 256))
        ->call('saveProject')
        ->assertHasErrors(['name' => 'max']);
});

test('project form generates slug from name', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', 'Meu Novo Projeto')
        ->set('emoji', '📋')
        ->set('color', '#3b82f6')
        ->call('saveProject');

    $project = Project::first();
    expect($project->slug)->toBe('meu-novo-projeto');
});

test('project form prevents duplicate slugs', function () {
    Project::factory()->create(['name' => 'Projeto Teste', 'slug' => 'projeto-teste']);

    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', 'Projeto Teste')
        ->set('emoji', '📋')
        ->set('color', '#3b82f6')
        ->call('saveProject')
        ->assertHasErrors('name');

    expect(Project::count())->toBe(1);
});

test('project form updates existing project', function () {
    $project = Project::factory()->create([
        'name' => 'Nome Original',
        'emoji' => '📋',
    ]);

    Livewire::test('project-form')
        ->dispatch('edit-project', projectId: $project->id)
        ->set('name', 'Nome Atualizado')
        ->set('emoji', '🎯')
        ->call('saveProject');

    $project->refresh();
    expect($project)
        ->name->toBe('Nome Atualizado')
        ->emoji->toBe('🎯');
});

test('project form dispatches project-created event on create', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', 'Novo Projeto')
        ->set('emoji', '📋')
        ->set('color', '#3b82f6')
        ->call('saveProject')
        ->assertDispatched('project-created');
});

test('project form dispatches project-updated event on update', function () {
    $project = Project::factory()->create();

    Livewire::test('project-form')
        ->dispatch('edit-project', projectId: $project->id)
        ->call('saveProject')
        ->assertDispatched('project-updated');
});

test('project form resets fields when opening new form', function () {
    $project = Project::factory()->create([
        'name' => 'Projeto Existente',
        'emoji' => '🎯',
    ]);

    Livewire::test('project-form')
        ->dispatch('edit-project', projectId: $project->id)
        ->assertSet('name', 'Projeto Existente')
        ->dispatch('open-project-form')
        ->assertSet('projectId', null)
        ->assertSet('name', '')
        ->assertSet('emoji', '📋')
        ->assertSet('color', '#3b82f6')
        ->assertSet('priority', 'medium')
        ->assertSet('status', 'active')
        ->assertSet('description', '');
});

test('project form closes modal after saving', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', 'Novo Projeto')
        ->set('emoji', '📋')
        ->set('color', '#3b82f6')
        ->call('saveProject')
        ->assertSet('showModal', false);
});

test('project form allows same slug when updating same project', function () {
    $project = Project::factory()->create(['name' => 'Projeto Teste', 'slug' => 'projeto-teste']);

    Livewire::test('project-form')
        ->dispatch('edit-project', projectId: $project->id)
        ->call('saveProject')
        ->assertHasNoErrors();
});

test('project form creates a project linked to an existing client', function () {
    $client = Client::factory()->create();

    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('name', 'Projeto com Cliente')
        ->set('emoji', '📋')
        ->set('color', '#3b82f6')
        ->set('clientId', $client->id)
        ->call('saveProject')
        ->assertHasNoErrors();

    expect(Project::first())->client_id->toBe($client->id);
});

test('project form updates the client link of an existing project', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => null]);

    Livewire::test('project-form')
        ->dispatch('edit-project', projectId: $project->id)
        ->assertSet('clientId', null)
        ->set('clientId', $client->id)
        ->call('saveProject');

    expect($project->fresh()->client_id)->toBe($client->id);
});

test('project form creates a client inline and links it to the project', function () {
    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('showInlineClientForm', true)
        ->set('newClientName', 'Novo Cliente Inline')
        ->call('createInlineClient')
        ->assertHasNoErrors()
        ->assertSet('showInlineClientForm', false);

    $client = Client::where('name', 'Novo Cliente Inline')->first();

    expect($client)->not->toBeNull();

    Livewire::test('project-form')
        ->dispatch('open-project-form')
        ->set('showInlineClientForm', true)
        ->set('newClientName', 'Novo Cliente Inline')
        ->call('createInlineClient')
        ->assertHasErrors(['newClientName']);
});
