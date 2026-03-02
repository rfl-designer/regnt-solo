<?php

use App\Enums\StakeholderIssueStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\StakeholderIssue;
use App\Models\Task;
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
        ->assertSee('Issues de Feedback')
        ->assertSee('Ver tasks');
});

it('allows stakeholder to add an issue comment from the sidebar', function () {
    Livewire::test('pages::project-stakeholder-view', ['token' => $this->stakeholder->access_token])
        ->set('newIssueComment', 'Precisamos de um relatório com filtros por período e cliente.')
        ->set('newIssueStatus', StakeholderIssueStatus::ToFeature->value)
        ->call('addIssue');

    $issue = StakeholderIssue::query()->first();

    expect($issue)->not->toBeNull();
    expect($issue->project_id)->toBe($this->project->id);
    expect($issue->stakeholder_id)->toBe($this->stakeholder->id);
    expect($issue->status)->toBe(StakeholderIssueStatus::ToFeature);
});

it('updates only the current stakeholder issue status', function () {
    $ownIssue = StakeholderIssue::factory()->create([
        'project_id' => $this->project->id,
        'stakeholder_id' => $this->stakeholder->id,
        'status' => StakeholderIssueStatus::Unread,
    ]);

    $otherStakeholder = Stakeholder::factory()->create();
    $otherIssue = StakeholderIssue::factory()->create([
        'project_id' => $otherStakeholder->project_id,
        'stakeholder_id' => $otherStakeholder->id,
        'status' => StakeholderIssueStatus::Unread,
    ]);

    Livewire::test('pages::project-stakeholder-view', ['token' => $this->stakeholder->access_token])
        ->call('updateIssueStatus', $ownIssue->id, StakeholderIssueStatus::Archived->value)
        ->call('updateIssueStatus', $otherIssue->id, StakeholderIssueStatus::Archived->value);

    expect($ownIssue->fresh()->status)->toBe(StakeholderIssueStatus::Archived);
    expect($otherIssue->fresh()->status)->toBe(StakeholderIssueStatus::Unread);
});

it('returns 404 for invalid stakeholder token', function () {
    $this->withoutVite();

    $this->get('/projects/shared/token-invalido')->assertNotFound();
});
