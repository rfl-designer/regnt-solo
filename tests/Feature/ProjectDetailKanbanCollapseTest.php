<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('feature board exposes per-column collapse state for every status', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Per-column collapse store (replaces the old single doneCollapsed boolean)
    $component
        ->assertSee('project-feature-collapsed', false)
        ->assertSee('toggleColumn(', false)
        ->assertSee('isCollapsed(', false)
        ->assertDontSee('doneCollapsed', false);

    // Every column wires its collapse state to its own status value
    foreach (['backlog', 'todo', 'doing', 'done'] as $status) {
        $component->assertSee("isCollapsed('{$status}')", false);
        $component->assertSee("toggleColumn('{$status}')", false);
    }
});

test('drill-down task board exposes per-column collapse state for every status', function () {
    $project = Project::factory()->create();
    $feature = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::Doing,
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->set('drillFeatureId', $feature->id);

    $component
        ->assertSee('project-drill-collapsed', false)
        ->assertDontSee('drillDoneCollapsed', false);

    foreach (['backlog', 'todo', 'doing', 'done'] as $status) {
        $component->assertSee("isCollapsed('{$status}')", false);
        $component->assertSee("toggleColumn('{$status}')", false);
    }
});
