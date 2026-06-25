<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('feature kanban shows initial limit of 10 features per column', function () {
    $project = Project::factory()->create();

    // 15 features in Backlog status
    Activity::factory()->epic()->backlog()->count(15)->create([
        'project_id' => $project->id,
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // The backlog column should be capped at the initial limit of 10
    expect($component->instance()->getColumnFeatures(ActivityStatus::Backlog))->toHaveCount(10);
});

test('load more button increases limit by 10', function () {
    $project = Project::factory()->create();

    // 25 features in Backlog status
    Activity::factory()->epic()->backlog()->count(25)->create([
        'project_id' => $project->id,
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Initial limit shows only 10 of the 25 backlog features
    expect($component->instance()->getColumnFeatures(ActivityStatus::Backlog))->toHaveCount(10);

    // Load more bumps the limit to 20
    $component->call('loadMore', 'backlog');
    expect($component->instance()->getColumnFeatures(ActivityStatus::Backlog))->toHaveCount(20);

    // Loading more again shows all 25
    $component->call('loadMore', 'backlog');
    expect($component->instance()->getColumnFeatures(ActivityStatus::Backlog))->toHaveCount(25);
});

// ADR: Draft status retired. This test now uses Todo and Backlog as two independent columns.
test('each column has independent lazy loading', function () {
    $project = Project::factory()->create();

    // 15 features in Todo status
    Activity::factory()->epic()->todo()->count(15)->create(['project_id' => $project->id]);

    // 25 features in Backlog status
    Activity::factory()->epic()->backlog()->count(25)->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Load more in backlog only
    $component->call('loadMore', 'backlog');

    // Backlog should now show 20, while todo stays capped at the initial 10
    expect($component->instance()->getColumnFeatures(ActivityStatus::Backlog))->toHaveCount(20);
    expect($component->instance()->getColumnFeatures(ActivityStatus::Todo))->toHaveCount(10);
});

test('feature kanban renders load more button when there are more features', function () {
    $project = Project::factory()->create();

    // Create 15 features with backlog tasks
    $features = Activity::factory()->epic()->count(15)->create(['project_id' => $project->id]);
    foreach ($features as $feature) {
        Activity::factory()->backlog()->create(['parent_id' => $feature->id, 'project_id' => $project->id]);
    }

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should render the load more button
    $component->assertSeeHtml('Carregar mais (5 restantes)');
});

test('feature kanban does not render load more button when all features are shown', function () {
    $project = Project::factory()->create();

    // Create 8 features with backlog tasks
    $features = Activity::factory()->epic()->count(8)->create(['project_id' => $project->id]);
    foreach ($features as $feature) {
        Activity::factory()->backlog()->create(['parent_id' => $feature->id, 'project_id' => $project->id]);
    }

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should not render the load more button
    $component->assertDontSeeHtml('Carregar mais');
});

test('limits property has correct initial values', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // ADR: Draft status retired. Board has 4 columns: Backlog/Todo/Doing/Done.
    expect($component->get('limits'))->toBe([
        'backlog' => 10,
        'todo' => 10,
        'doing' => 10,
        'done' => 10,
    ]);
});

test('load more updates the correct status limit', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Load more in backlog
    $component->call('loadMore', 'backlog');

    expect($component->get('limits')['backlog'])->toBe(20);
    expect($component->get('limits')['todo'])->toBe(10);
});
