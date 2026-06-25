<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ========================================
// Kanban renders features (not tasks)
// ========================================

// ADR: Draft status retired. Board has 4 columns: Backlog/Todo/Doing/Done.
// Features that previously used 'Draft' status now use 'Backlog'.
test('project board shows features grouped by explicit status', function () {
    $project = Project::factory()->create();

    // Each feature has explicit status set (Draft replaced by Backlog)
    Activity::factory()->epic()->create(['project_id' => $project->id, 'title' => 'Feature Backlog', 'status' => ActivityStatus::Backlog]);
    Activity::factory()->epic()->create(['project_id' => $project->id, 'title' => 'Feature A Fazer', 'status' => ActivityStatus::Todo]);
    Activity::factory()->epic()->create(['project_id' => $project->id, 'title' => 'Feature Em Progresso', 'status' => ActivityStatus::Doing]);
    Activity::factory()->epic()->create(['project_id' => $project->id, 'title' => 'Feature Concluida', 'status' => ActivityStatus::Done]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $component
        ->assertSee('Feature Backlog')
        ->assertSee('Feature A Fazer')
        ->assertSee('Feature Em Progresso')
        ->assertSee('Feature Concluida');
});

// ADR: Draft status retired. Board now has 4 columns (Backlog/Todo/Doing/Done). No Rascunho column.
test('project board kanban has all four column headers', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Backlog')
        ->assertSee('A Fazer')
        ->assertSee('Fazendo')
        ->assertSee('Concluída');
});

// ========================================
// Backlog column (previously Draft column)
// ========================================

// ADR: Draft status retired; features without explicit status land in Backlog.
test('backlog column shows features with backlog status', function () {
    $project = Project::factory()->create();

    $backlog = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Feature Sem Tasks',
        'status' => ActivityStatus::Backlog,
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $backlogFeatures = $component->instance()->getColumnFeatures(ActivityStatus::Backlog);
    expect($backlogFeatures)->toHaveCount(1);
    expect($backlogFeatures->first()->id)->toBe($backlog->id);

    $component->assertSee('Feature Sem Tasks');
});

test('backlog column does not include features with non-backlog status', function () {
    $project = Project::factory()->create();

    Activity::factory()->epic()->create(['project_id' => $project->id, 'title' => 'Backlog Feature', 'status' => ActivityStatus::Backlog]);
    Activity::factory()->epic()->create(['project_id' => $project->id, 'title' => 'Todo Feature', 'status' => ActivityStatus::Todo]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $backlogFeatures = $component->instance()->getColumnFeatures(ActivityStatus::Backlog);
    expect($backlogFeatures)->toHaveCount(1);
    expect($backlogFeatures->first()->title)->toBe('Backlog Feature');
});

test('backlog column count reflects features with backlog status', function () {
    $project = Project::factory()->create();

    Activity::factory()->epic()->count(4)->create(['project_id' => $project->id, 'status' => ActivityStatus::Backlog]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    expect($component->instance()->getColumnTotal(ActivityStatus::Backlog))->toBe(4);
});

// ========================================
// Features tab renders specs as documents
// ========================================

test('features tab shows feature specs rendered as documents', function () {
    $project = Project::factory()->create();

    $feature = Activity::factory()->epic()->create([
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

    Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Feature sem selecao',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('Selecione uma feature');
});

// ADR: ActivityStatus::Draft retired. Feature with Backlog status shows 'Backlog' badge.
test('features tab shows status badge for each feature', function () {
    $project = Project::factory()->create();

    Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Feature Backlog Tab',
        'status' => ActivityStatus::Backlog,
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('Feature Backlog Tab')
        ->assertSee('Backlog');
});

test('features tab shows progress count when feature has tasks', function () {
    $project = Project::factory()->create();

    $feature = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Feature Progresso Tab',
    ]);

    Activity::factory()->done()->create(['parent_id' => $feature->id, 'project_id' => $project->id]);
    Activity::factory()->todo()->create(['parent_id' => $feature->id, 'project_id' => $project->id]);
    Activity::factory()->todo()->create(['parent_id' => $feature->id, 'project_id' => $project->id]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('1/3 tasks');
});

test('features tab shows priority badge when feature has priority', function () {
    $project = Project::factory()->create();

    Activity::factory()->epic()->urgent()->create([
        'project_id' => $project->id,
        'title' => 'Feature Urgente Tab',
    ]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('tab', 'features')
        ->assertSee('Urgente');
});

test('features tab shows due date when feature has due date', function () {
    $project = Project::factory()->create();

    Activity::factory()->epic()->create([
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

    $feature = Activity::factory()->epic()->create([
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

    $feature = Activity::factory()->epic()->create([
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

    $feature = Activity::factory()->epic()->create([
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

    Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Feature Deste Projeto',
    ]);
    Activity::factory()->epic()->create([
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

    $otherFeature = Activity::factory()->epic()->create([
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

    Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Feature Board Projeto',
    ]);
    Activity::factory()->epic()->create([
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

    Activity::factory()->epic()->count(5)->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $metrics = $component->get('metrics');

    expect($metrics['feature_count'])->toBe(5);
});

// ADR: Draft retired, replaced by Backlog. Metrics count done features by explicit status.
test('metrics includes done features count', function () {
    $project = Project::factory()->create();

    // 2 done features (explicit status)
    Activity::factory()->epic()->create(['project_id' => $project->id, 'status' => ActivityStatus::Done]);
    Activity::factory()->epic()->create(['project_id' => $project->id, 'status' => ActivityStatus::Done]);

    // 1 not-done feature (Backlog instead of Draft)
    Activity::factory()->epic()->create(['project_id' => $project->id, 'status' => ActivityStatus::Backlog]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $metrics = $component->get('metrics');

    expect($metrics['done_features'])->toBe(2);
    expect($metrics['feature_count'])->toBe(3);
});

// ADR: Draft retired. Replaced with Backlog in feature_completion_percent test.
test('metrics includes feature completion percentage', function () {
    $project = Project::factory()->create();

    // 2 done features
    Activity::factory()->epic()->create(['project_id' => $project->id, 'status' => ActivityStatus::Done]);
    Activity::factory()->epic()->create(['project_id' => $project->id, 'status' => ActivityStatus::Done]);

    // 2 not-done features (Backlog instead of Draft)
    Activity::factory()->epic()->count(2)->create(['project_id' => $project->id, 'status' => ActivityStatus::Backlog]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $metrics = $component->get('metrics');

    expect($metrics['feature_completion_percent'])->toBe(50.0);
});

// ADR: Draft retired. features_by_status no longer has a 'draft' key; use 'backlog' instead.
test('metrics includes features by status breakdown', function () {
    $project = Project::factory()->create();

    Activity::factory()->epic()->count(2)->create(['project_id' => $project->id, 'status' => ActivityStatus::Backlog]);
    Activity::factory()->epic()->create(['project_id' => $project->id, 'status' => ActivityStatus::Todo]);
    Activity::factory()->epic()->create(['project_id' => $project->id, 'status' => ActivityStatus::Done]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $metrics = $component->get('metrics');

    expect($metrics['features_by_status']['backlog'])->toBe(2);
    expect($metrics['features_by_status']['todo'])->toBe(1);
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

// ADR: Draft retired. featuresByStatus uses Backlog instead of Draft.
test('featuresByStatus groups features by explicit status', function () {
    $project = Project::factory()->create();

    Activity::factory()->epic()->count(2)->create(['project_id' => $project->id, 'status' => ActivityStatus::Backlog]);
    Activity::factory()->epic()->create(['project_id' => $project->id, 'status' => ActivityStatus::Doing]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);
    $byStatus = $component->get('featuresByStatus');

    expect($byStatus['backlog'])->toHaveCount(2);
    expect($byStatus['doing'])->toHaveCount(1);
    expect($byStatus['todo'])->toHaveCount(0);
    expect($byStatus['done'])->toHaveCount(0);
});
