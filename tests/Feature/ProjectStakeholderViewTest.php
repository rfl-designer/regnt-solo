<?php

use App\Models\Feature;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\Task;

beforeEach(function () {
    $this->project = Project::factory()->create(['name' => 'Projeto Beta']);
    $this->stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'External User',
        'email' => 'external@example.com',
    ]);
});

it('stakeholder belongs to correct project', function () {
    expect($this->stakeholder->project->id)->toBe($this->project->id);
});

it('updates last_accessed_at when opening public link', function () {
    $this->withoutVite();

    $this->stakeholder->update(['last_accessed_at' => null]);

    $this->get($this->stakeholder->public_url)->assertSuccessful();

    expect($this->stakeholder->fresh()->last_accessed_at)->not->toBeNull();
});

it('public url is available through accessor', function () {
    expect($this->stakeholder->public_url)
        ->toBeString()
        ->toContain('/projects/shared/');
});

it('renders stakeholder view with project detail tabs and feature board', function () {
    $this->withoutVite();

    $feature = Feature::factory()->withSpec()->create([
        'project_id' => $this->project->id,
        'title' => 'Portal de Stakeholders',
    ]);

    Task::factory()->forFeature($feature)->doing()->create([
        'title' => 'Implementar board de acompanhamento',
    ]);

    Task::factory()->forFeature($feature)->todo()->create([
        'title' => 'Revisar especificações da feature',
    ]);

    $this->get($this->stakeholder->public_url)
        ->assertSuccessful()
        ->assertSee('Board')
        ->assertSee('Features')
        ->assertSee('Docs')
        ->assertSee('Métricas')
        ->assertSee('Portal de Stakeholders')
        ->assertSee('Ver tasks');
});

it('returns 404 for invalid stakeholder token', function () {
    $this->withoutVite();

    $this->get('/projects/shared/token-invalido')->assertNotFound();
});
