<?php

use App\Enums\FeatureStatus;
use App\Enums\ProjectStatus;
use App\Enums\StakeholderIssueStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\StakeholderIssue;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('project detail route requires authentication', function () {
    auth()->logout();
    $project = Project::factory()->create();

    $this->get(route('project.detail', $project->slug))
        ->assertRedirect(route('login'));
});

test('project detail page renders for authenticated users', function () {
    $project = Project::factory()->create();

    $this->get(route('project.detail', $project->slug))
        ->assertOk();
});

test('project detail component renders with project data', function () {
    $project = Project::factory()->create([
        'name' => 'Meu Projeto',
        'emoji' => '🚀',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSuccessful()
        ->assertSee('🚀')
        ->assertSee('Meu Projeto');
});

test('project detail shows status and priority badges', function () {
    $project = Project::factory()->highPriority()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee($project->status->label())
        ->assertSee($project->priority->label());
});

test('project detail shows description when present', function () {
    $project = Project::factory()->create([
        'description' => 'Uma descrição detalhada do projeto',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Uma descrição detalhada do projeto');
});

test('project detail board shows features by status', function () {
    $project = Project::factory()->create();

    // Draft feature (no tasks)
    $draft = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Feature Draft']);

    // Backlog feature
    $backlog = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Feature Backlog']);
    Task::factory()->backlog()->create(['feature_id' => $backlog->id, 'project_id' => $project->id]);

    // Todo feature
    $todo = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Feature Todo']);
    Task::factory()->todo()->create(['feature_id' => $todo->id, 'project_id' => $project->id]);

    // Doing feature
    $doing = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Feature Doing']);
    Task::factory()->doing()->create(['feature_id' => $doing->id, 'project_id' => $project->id]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Feature Draft')
        ->assertSee('Feature Backlog')
        ->assertSee('Feature Todo')
        ->assertSee('Feature Doing');
});

test('project detail metrics shows total tasks count', function () {
    $project = Project::factory()->create();
    Task::factory()->count(5)->todo()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $metrics = $component->get('metrics');
    expect($metrics['total'])->toBe(5);
});

test('project detail metrics shows completion percentage', function () {
    $project = Project::factory()->create();
    Task::factory()->count(3)->todo()->create(['project_id' => $project->id]);
    Task::factory()->count(2)->done()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $metrics = $component->get('metrics');
    expect($metrics['completion_percent'])->toBe(40.0);
});

test('project detail metrics shows zero percent when no tasks', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $metrics = $component->get('metrics');
    expect($metrics['completion_percent'])->toBe(0);
});

test('project detail metrics includes feature count', function () {
    $project = Project::factory()->create();
    Feature::factory()->count(3)->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $metrics = $component->get('metrics');
    expect($metrics['feature_count'])->toBe(3);
});

test('project detail shows archive button with danger styling for active projects', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSeeHtml('Arquivar')
        ->assertSeeHtml('text-red-400');
});

test('project detail shows archive confirmation modal when button clicked', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('showArchiveModal', true)
        ->assertSet('showArchiveModal', true)
        ->assertSee('Arquivar projeto')
        ->assertSee('Tem certeza que deseja arquivar');
});

test('project detail archive modal closes after archiving', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('showArchiveModal', true)
        ->call('archiveProject')
        ->assertSet('showArchiveModal', false);
});

test('project detail archive project changes status', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('archiveProject')
        ->assertDispatched('project-updated');

    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('project detail shows reactivate button instead of archive for archived projects', function () {
    $project = Project::factory()->archived()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Reativar')
        ->assertDontSeeHtml('wire:click="$set(\'showArchiveModal\', true)"');
});

test('project detail activate project changes status', function () {
    $project = Project::factory()->archived()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('activateProject')
        ->assertDispatched('project-updated');

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('project detail listens to feature-created event', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->dispatch('feature-created')
        ->assertSuccessful();
});

test('project detail listens to task-updated event', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->dispatch('task-updated')
        ->assertSuccessful();
});

test('project detail listens to project-updated event', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->dispatch('project-updated')
        ->assertSuccessful();
});

test('project detail returns 404 for non-existent slug', function () {
    $this->get(route('project.detail', 'non-existent-slug'))
        ->assertNotFound();
});

test('project detail shows kanban column headers', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Rascunho')
        ->assertSee('Backlog')
        ->assertSee('A Fazer')
        ->assertSee('Em Progresso')
        ->assertSee('Concluída');
});

test('project detail docs tab shows documents list', function () {
    $project = Project::factory()->create();
    $document = \App\Models\Document::factory()->create([
        'project_id' => $project->id,
        'title' => 'Meu Documento de Teste',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'docs')
        ->assertSee('Meu Documento de Teste');
});

test('project detail docs tab shows empty state when no documents', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'docs')
        ->assertSee('Nenhum documento neste projeto.');
});

test('project detail issues tab shows stakeholder issues from the project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $project->id,
        'email' => 'stakeholder.projeto@example.com',
    ]);

    StakeholderIssue::factory()->create([
        'project_id' => $project->id,
        'stakeholder_id' => $stakeholder->id,
        'comment' => 'Issue do projeto principal',
    ]);

    $otherStakeholder = Stakeholder::factory()->create(['project_id' => $otherProject->id]);
    StakeholderIssue::factory()->create([
        'project_id' => $otherProject->id,
        'stakeholder_id' => $otherStakeholder->id,
        'comment' => 'Issue de outro projeto',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'issues')
        ->assertSee('Issue do projeto principal')
        ->assertSee('stakeholder.projeto@example.com')
        ->assertDontSee('Issue de outro projeto');
});

test('project detail can mark stakeholder issue as to feature', function () {
    $project = Project::factory()->create();
    $stakeholder = Stakeholder::factory()->create(['project_id' => $project->id]);
    $issue = StakeholderIssue::factory()->create([
        'project_id' => $project->id,
        'stakeholder_id' => $stakeholder->id,
        'status' => StakeholderIssueStatus::Unread,
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('markIssueAsToFeature', $issue->id);

    expect($issue->fresh()->status)->toBe(StakeholderIssueStatus::ToFeature);
});

test('project detail can archive stakeholder issue', function () {
    $project = Project::factory()->create();
    $stakeholder = Stakeholder::factory()->create(['project_id' => $project->id]);
    $issue = StakeholderIssue::factory()->create([
        'project_id' => $project->id,
        'stakeholder_id' => $stakeholder->id,
        'status' => StakeholderIssueStatus::Unread,
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('archiveIssue', $issue->id);

    expect($issue->fresh()->status)->toBe(StakeholderIssueStatus::Archived);
});

test('project detail can select document for preview', function () {
    $project = Project::factory()->create();
    $document = \App\Models\Document::factory()->create([
        'project_id' => $project->id,
        'title' => 'Documento Preview',
        'content' => '# Conteúdo do documento',
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'docs')
        ->call('selectDocument', $document->id);

    expect($component->get('selectedDocumentId'))->toBe($document->id);
    $component->assertSee('Documento Preview');
});

test('project detail can clear document selection by setting to null', function () {
    $project = Project::factory()->create();
    $document = \App\Models\Document::factory()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('selectDocument', $document->id)
        ->set('selectedDocumentId', null);

    expect($component->get('selectedDocumentId'))->toBeNull();
});

test('project detail selectedDocument computed returns correct document', function () {
    $project = Project::factory()->create();
    $document = \App\Models\Document::factory()->create([
        'project_id' => $project->id,
        'title' => 'Documento Selecionado',
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('selectDocument', $document->id);

    $selectedDocument = $component->get('selectedDocument');
    expect($selectedDocument)->not->toBeNull();
    expect($selectedDocument->id)->toBe($document->id);
    expect($selectedDocument->title)->toBe('Documento Selecionado');
});

test('project detail selectedDocument computed returns null when no selection', function () {
    $project = Project::factory()->create();
    \App\Models\Document::factory()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    expect($component->get('selectedDocument'))->toBeNull();
});

// ========================================
// Feature Kanban Tests
// ========================================

test('project detail board tab is default active tab', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    expect($component->get('tab'))->toBe('board');
});

test('project detail kanban loadMore increases limit by 10', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $initialLimit = $component->get('limits')['backlog'];
    expect($initialLimit)->toBe(10);

    $component->call('loadMore', 'backlog');

    expect($component->get('limits')['backlog'])->toBe(20);
});

test('project detail feature kanban shows feature progress', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature com Progresso',
    ]);

    Task::factory()->todo()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);
    Task::factory()->done()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Feature com Progresso')
        ->assertSee('1/2 tasks');
});

test('project detail feature kanban shows priority badge', function () {
    $project = Project::factory()->create();
    Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Urgente',
        'priority' => 'urgent',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Urgente');
});

test('project detail feature kanban shows new feature button', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Nova Feature');
});

test('project detail feature kanban shows empty state per column', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Nenhuma feature');
});

test('project detail getColumnFeatures returns features by computed status', function () {
    $project = Project::factory()->create();

    // Draft feature (no tasks)
    $draft = Feature::factory()->create(['project_id' => $project->id]);

    // Backlog feature
    $backlog = Feature::factory()->create(['project_id' => $project->id, 'status' => FeatureStatus::Backlog]);
    Task::factory()->backlog()->create(['feature_id' => $backlog->id, 'project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $draftFeatures = $component->instance()->getColumnFeatures(FeatureStatus::Draft);
    $backlogFeatures = $component->instance()->getColumnFeatures(FeatureStatus::Backlog);

    expect($draftFeatures)->toHaveCount(1);
    expect($draftFeatures->first()->id)->toBe($draft->id);
    expect($backlogFeatures)->toHaveCount(1);
    expect($backlogFeatures->first()->id)->toBe($backlog->id);
});

test('project detail getColumnTotal counts features by computed status', function () {
    $project = Project::factory()->create();

    // Create 3 draft features (no tasks)
    Feature::factory()->count(3)->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $total = $component->instance()->getColumnTotal(FeatureStatus::Draft);
    expect($total)->toBe(3);
});

test('project detail feature kanban does not show features from other projects', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature do Projeto',
    ]);
    Feature::factory()->create([
        'project_id' => $otherProject->id,
        'title' => 'Feature de Outro Projeto',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Feature do Projeto')
        ->assertDontSee('Feature de Outro Projeto');
});

test('project detail feature kanban shows timer controls', function () {
    $project = Project::factory()->create();
    Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature com Timer',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Feature com Timer');
});

test('project detail startTimer starts timer for feature', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->create(['project_id' => $project->id]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('startTimer', $feature->id)
        ->assertDispatched('timer-updated');

    expect($feature->fresh()->isRunning())->toBeTrue();
});

test('project detail toggleExpanded toggles feature expansion', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    expect($component->instance()->isExpanded($feature->id))->toBeFalse();

    $component->call('toggleExpanded', $feature->id);

    expect($component->instance()->isExpanded($feature->id))->toBeTrue();
});
