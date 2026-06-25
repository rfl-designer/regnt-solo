<?php

use App\Enums\ActivityStatus;
use App\Enums\StakeholderIssueStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\StakeholderIssue;
use Livewire\Livewire;

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

    $feature = Activity::factory()->epic()->withSpec()->create([
        'project_id' => $this->project->id,
        'title' => 'Portal de Stakeholders',
        'status' => ActivityStatus::Doing,
    ]);

    Activity::factory()->forParent($feature)->doing()->create([
        'title' => 'Implementar board de acompanhamento',
    ]);

    Activity::factory()->forParent($feature)->todo()->create([
        'title' => 'Revisar especificações da feature',
    ]);

    $this->get($this->stakeholder->public_url)
        ->assertSuccessful()
        ->assertSee('Board')
        ->assertSee('Features')
        ->assertSee('Docs')
        ->assertSee('Métricas')
        ->assertSee('Portal de Stakeholders')
        ->assertSee('Issues de Feedback')
        ->assertSee('Ver tasks');
});

it('drill shows slices but never personal tasks parented to the epic', function () {
    $this->withoutVite();

    $feature = Activity::factory()->epic()->create([
        'project_id' => $this->project->id,
        'status' => ActivityStatus::Doing,
    ]);

    Activity::factory()->forParent($feature)->issue()->doing()->create([
        'title' => 'Fatia visivel do epico',
    ]);

    Activity::factory()->forParent($feature)->task()->doing()->create([
        'title' => 'Recado pessoal do dev',
    ]);

    Livewire::test('pages::project-stakeholder-view', ['token' => $this->stakeholder->access_token])
        ->call('enterDrill', $feature->id)
        ->assertSee('Fatia visivel do epico')
        ->assertSee('Fatia')
        ->assertDontSee('Recado pessoal do dev');
});

it('allows stakeholder to add an issue comment from the sidebar', function () {
    Livewire::test('pages::project-stakeholder-view', ['token' => $this->stakeholder->access_token])
        ->set('newIssueComment', 'Precisamos de um relatório com filtros por período e cliente.')
        ->call('addIssue');

    $issue = StakeholderIssue::query()->first();

    expect($issue)->not->toBeNull();
    expect($issue->project_id)->toBe($this->project->id);
    expect($issue->stakeholder_id)->toBe($this->stakeholder->id);
    expect($issue->status)->toBe(StakeholderIssueStatus::Unread);
});

it('shows issue history with stakeholder email in the panel', function () {
    $ownIssue = StakeholderIssue::factory()->create([
        'project_id' => $this->project->id,
        'stakeholder_id' => $this->stakeholder->id,
        'status' => StakeholderIssueStatus::Unread,
        'comment' => 'Issue enviada pelo link público.',
    ]);

    Livewire::test('pages::project-stakeholder-view', ['token' => $this->stakeholder->access_token])
        ->assertSee($ownIssue->comment)
        ->assertSee($this->stakeholder->email)
        ->assertSee('Novo comentário / issue')
        ->assertSee('Enviar');
});

it('can hide and show issues panel in stakeholder view', function () {
    Livewire::test('pages::project-stakeholder-view', ['token' => $this->stakeholder->access_token])
        ->assertSet('showIssuesPanel', true)
        ->assertSee('Issues de Feedback')
        ->assertSee('Esconder issues')
        ->call('toggleIssuesPanel')
        ->assertSet('showIssuesPanel', false)
        ->assertDontSee('Issues de Feedback')
        ->assertSee('Mostrar issues')
        ->call('toggleIssuesPanel')
        ->assertSet('showIssuesPanel', true)
        ->assertSee('Issues de Feedback')
        ->assertSee('Esconder issues');
});

it('returns 404 for invalid stakeholder token', function () {
    $this->withoutVite();

    $this->get('/projects/shared/token-invalido')->assertNotFound();
});
