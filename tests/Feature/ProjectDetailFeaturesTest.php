<?php

use App\Enums\FeatureStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ========================================
// Kanban renders features (not tasks)
// ========================================

test('project board shows features grouped by computed status', function () {
    $project = Project::factory()->create();

    // Draft = no tasks
    $draft = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Feature Rascunho']);

    // Backlog = only backlog tasks
    $backlog = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Feature Backlog']);
    Task::factory()->backlog()->create(['feature_id' => $backlog->id, 'project_id' => $project->id]);

    // Todo = has todo tasks
    $todo = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Feature A Fazer']);
    Task::factory()->todo()->create(['feature_id' => $todo->id, 'project_id' => $project->id]);

    // Doing = has doing tasks
    $doing = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Feature Em Progresso']);
    Task::factory()->doing()->create(['feature_id' => $doing->id, 'project_id' => $project->id]);

    // Done = all tasks done
    $done = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Feature Concluida']);
    Task::factory()->done()->create(['feature_id' => $done->id, 'project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $component
        ->assertSee('Feature Rascunho')
        ->assertSee('Feature Backlog')
        ->assertSee('Feature A Fazer')
        ->assertSee('Feature Em Progresso')
        ->assertSee('Feature Concluida');
});

test('project board kanban has all five column headers', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Rascunho')
        ->assertSee('Backlog')
        ->assertSee('A Fazer')
        ->assertSee('Em Progresso')
        ->assertSee('Concluída');
});

// ========================================
// Draft column for features without tasks
// ========================================

test('draft column shows features with no tasks', function () {
    $project = Project::factory()->create();

    $draft = Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Sem Tasks',
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $draftFeatures = $component->instance()->getColumnFeatures(FeatureStatus::Draft);
    expect($draftFeatures)->toHaveCount(1);
    expect($draftFeatures->first()->id)->toBe($draft->id);

    $component->assertSee('Feature Sem Tasks');
});

test('draft column does not include features with tasks', function () {
    $project = Project::factory()->create();

    // Draft (no tasks)
    Feature::factory()->create(['project_id' => $project->id, 'title' => 'Draft Feature']);

    // Has tasks (not draft)
    $withTasks = Feature::factory()->create(['project_id' => $project->id, 'title' => 'Backlog Feature']);
    Task::factory()->backlog()->create(['feature_id' => $withTasks->id, 'project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $draftFeatures = $component->instance()->getColumnFeatures(FeatureStatus::Draft);
    expect($draftFeatures)->toHaveCount(1);
    expect($draftFeatures->first()->title)->toBe('Draft Feature');
});

test('draft column count reflects features without tasks', function () {
    $project = Project::factory()->create();

    Feature::factory()->count(4)->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    expect($component->instance()->getColumnTotal(FeatureStatus::Draft))->toBe(4);
});

// ========================================
// Features tab renders specs as documents
// ========================================

test('features tab shows feature specs rendered as documents', function () {
    $project = Project::factory()->create();

    $feature = Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature com Spec',
        'spec' => '## User Story\nTexto exclusivo da spec renderizada.',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->call('selectFeature', $feature->id)
        ->assertSet('selectedFeatureId', $feature->id)
        ->assertSee('Feature com Spec')
        ->assertSeeHtml("feature-preview-{$feature->id}");
});

test('features tab shows selection prompt before selecting a feature', function () {
    $project = Project::factory()->create();

    Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature sem selecao',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('Selecione uma feature');
});

test('features tab shows status badge for each feature', function () {
    $project = Project::factory()->create();

    // Draft feature (no tasks)
    Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Draft Tab',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('Feature Draft Tab')
        ->assertSee('Rascunho');
});

test('features tab shows progress count when feature has tasks', function () {
    $project = Project::factory()->create();

    $feature = Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Progresso Tab',
    ]);

    Task::factory()->done()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);
    Task::factory()->todo()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);
    Task::factory()->todo()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('1/3 tasks');
});

test('features tab shows priority badge when feature has priority', function () {
    $project = Project::factory()->create();

    Feature::factory()->urgent()->create([
        'project_id' => $project->id,
        'title' => 'Feature Urgente Tab',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('Urgente');
});

test('features tab shows due date when feature has due date', function () {
    $project = Project::factory()->create();

    Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Com Prazo',
        'due_date' => '2026-03-15',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('15/03/2026');
});

test('features tab shows no spec message when spec is empty', function () {
    $project = Project::factory()->create();

    $feature = Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Sem Spec',
        'spec' => null,
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->call('selectFeature', $feature->id)
        ->assertSee('Nenhuma spec definida para esta feature.');
});

// ========================================
// Feature selection and edit actions
// ========================================

test('features tab list items trigger selectFeature action', function () {
    $project = Project::factory()->create();

    $feature = Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Selecionavel',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSeeHtml("wire:click=\"selectFeature({$feature->id})\"")
        ->call('selectFeature', $feature->id)
        ->assertSet('selectedFeatureId', $feature->id);
});

test('features tab preview renders spec content for selected feature', function () {
    $project = Project::factory()->create();

    $feature = Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Editavel',
        'spec' => '## Spec\nConteudo da preview.',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->call('selectFeature', $feature->id)
        ->assertSeeHtml("feature-preview-{$feature->id}");
});

// ========================================
// Features from other projects don't appear
// ========================================

test('features tab only shows features from current project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Deste Projeto',
    ]);
    Feature::factory()->create([
        'project_id' => $otherProject->id,
        'title' => 'Feature Outro Projeto',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('Feature Deste Projeto')
        ->assertDontSee('Feature Outro Projeto');
});

test('selectFeature ignores features from other projects', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    $otherFeature = Feature::factory()->create([
        'project_id' => $otherProject->id,
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->call('selectFeature', $otherFeature->id)
        ->assertSet('selectedFeatureId', null);
});

test('board kanban only shows features from current project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    Feature::factory()->create([
        'project_id' => $project->id,
        'title' => 'Feature Board Projeto',
    ]);
    Feature::factory()->create([
        'project_id' => $otherProject->id,
        'title' => 'Feature Board Outro',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Feature Board Projeto')
        ->assertDontSee('Feature Board Outro');
});

// ========================================
// Empty state when project has no features
// ========================================

test('features tab shows empty state when project has no features', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('Nenhuma feature neste projeto');
});

test('features tab empty state shows create button', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('Nova Feature');
});

test('board kanban shows empty state per column when no features', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Nenhuma feature');
});

// ========================================
// Metrics updated with feature counts
// ========================================

test('metrics includes feature count', function () {
    $project = Project::factory()->create();

    Feature::factory()->count(5)->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $metrics = $component->get('metrics');

    expect($metrics['feature_count'])->toBe(5);
});

test('metrics includes done features count', function () {
    $project = Project::factory()->create();

    // 2 done features
    $done1 = Feature::factory()->create(['project_id' => $project->id]);
    Task::factory()->done()->create(['feature_id' => $done1->id, 'project_id' => $project->id]);

    $done2 = Feature::factory()->create(['project_id' => $project->id]);
    Task::factory()->done()->create(['feature_id' => $done2->id, 'project_id' => $project->id]);

    // 1 not-done feature
    Feature::factory()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $metrics = $component->get('metrics');

    expect($metrics['done_features'])->toBe(2);
    expect($metrics['feature_count'])->toBe(3);
});

test('metrics includes feature completion percentage', function () {
    $project = Project::factory()->create();

    // 2 done features (all tasks done)
    $done1 = Feature::factory()->create(['project_id' => $project->id]);
    Task::factory()->done()->create(['feature_id' => $done1->id, 'project_id' => $project->id]);

    $done2 = Feature::factory()->create(['project_id' => $project->id]);
    Task::factory()->done()->create(['feature_id' => $done2->id, 'project_id' => $project->id]);

    // 2 not-done features (drafts)
    Feature::factory()->count(2)->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $metrics = $component->get('metrics');

    expect($metrics['feature_completion_percent'])->toBe(50.0);
});

test('metrics includes features by status breakdown', function () {
    $project = Project::factory()->create();

    // 2 drafts (no tasks)
    Feature::factory()->count(2)->create(['project_id' => $project->id]);

    // 1 backlog
    $backlog = Feature::factory()->create(['project_id' => $project->id]);
    Task::factory()->backlog()->create(['feature_id' => $backlog->id, 'project_id' => $project->id]);

    // 1 done
    $done = Feature::factory()->create(['project_id' => $project->id]);
    Task::factory()->done()->create(['feature_id' => $done->id, 'project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $metrics = $component->get('metrics');

    expect($metrics['features_by_status']['draft'])->toBe(2);
    expect($metrics['features_by_status']['backlog'])->toBe(1);
    expect($metrics['features_by_status']['todo'])->toBe(0);
    expect($metrics['features_by_status']['doing'])->toBe(0);
    expect($metrics['features_by_status']['done'])->toBe(1);
});

test('metrics feature completion is zero when no features', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $metrics = $component->get('metrics');

    expect($metrics['feature_completion_percent'])->toBe(0);
    expect($metrics['feature_count'])->toBe(0);
});

// ========================================
// featuresByStatus computed property
// ========================================

test('featuresByStatus groups features by computed status', function () {
    $project = Project::factory()->create();

    // 2 drafts
    Feature::factory()->count(2)->create(['project_id' => $project->id]);

    // 1 doing
    $doing = Feature::factory()->create(['project_id' => $project->id]);
    Task::factory()->doing()->create(['feature_id' => $doing->id, 'project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $byStatus = $component->get('featuresByStatus');

    expect($byStatus['draft'])->toHaveCount(2);
    expect($byStatus['doing'])->toHaveCount(1);
    expect($byStatus['backlog'])->toHaveCount(0);
    expect($byStatus['todo'])->toHaveCount(0);
    expect($byStatus['done'])->toHaveCount(0);
});
