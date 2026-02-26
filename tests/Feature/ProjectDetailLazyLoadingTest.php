<?php

use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('feature kanban shows initial limit of 10 features per column', function () {
    $project = Project::factory()->create();

    // Create 15 features with tasks (so they're in Backlog status)
    $features = Feature::factory()->count(15)->create([
        'project_id' => $project->id,
    ]);

    foreach ($features as $feature) {
        Task::factory()->backlog()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);
    }

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should show only 10 features
    foreach ($features->take(10) as $feature) {
        $component->assertSee($feature->title);
    }

    // Should not show features beyond the limit
    foreach ($features->skip(10) as $feature) {
        $component->assertDontSee($feature->title);
    }
});

test('load more button increases limit by 10', function () {
    $project = Project::factory()->create();

    // Create 25 features with tasks (Backlog status)
    $features = Feature::factory()->count(25)->create([
        'project_id' => $project->id,
    ]);

    foreach ($features as $feature) {
        Task::factory()->backlog()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);
    }

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should not show features beyond initial limit
    $component->assertDontSee($features[20]->title);

    // Load more
    $component->call('loadMore', 'backlog');

    // Should now show more features
    $component->assertSee($features[15]->title);

    // Should still not show all
    $component->assertDontSee($features[24]->title);

    // Load more again
    $component->call('loadMore', 'backlog');

    // Should now show all features
    $component->assertSee($features[24]->title);
});

test('each column has independent lazy loading', function () {
    $project = Project::factory()->create();

    // Create 15 draft features (no tasks)
    $draftFeatures = Feature::factory()->count(15)->create(['project_id' => $project->id]);

    // Create 25 features with backlog tasks
    $backlogFeatures = Feature::factory()->count(25)->create(['project_id' => $project->id]);
    foreach ($backlogFeatures as $feature) {
        Task::factory()->backlog()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);
    }

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Load more in backlog only
    $component->call('loadMore', 'backlog');

    // Backlog should now show 20
    $component->assertSee($backlogFeatures[15]->title);

    // Draft should still show only 10
    $component->assertDontSee($draftFeatures[14]->title);
});

test('feature kanban renders load more button when there are more features', function () {
    $project = Project::factory()->create();

    // Create 15 features with backlog tasks
    $features = Feature::factory()->count(15)->create(['project_id' => $project->id]);
    foreach ($features as $feature) {
        Task::factory()->backlog()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);
    }

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should render the load more button
    $component->assertSeeHtml('Carregar mais (5 restantes)');
});

test('feature kanban does not render load more button when all features are shown', function () {
    $project = Project::factory()->create();

    // Create 8 features with backlog tasks
    $features = Feature::factory()->count(8)->create(['project_id' => $project->id]);
    foreach ($features as $feature) {
        Task::factory()->backlog()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);
    }

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should not render the load more button
    $component->assertDontSeeHtml('Carregar mais');
});

test('limits property has correct initial values', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    expect($component->get('limits'))->toBe([
        'draft' => 10,
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
