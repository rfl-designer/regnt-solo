<?php

use App\Enums\FeatureStatus;
use App\Enums\TaskStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * Tasks seeded in bulk share an identical created_at, so the previous
 * `created_at DESC` tiebreaker could not resolve sort_order collisions in
 * favour of the drop — dragged cards snapped back. These tests pin the
 * reorder behaviour for all three Kanban handlers.
 */
function backlogOrder(): array
{
    return Task::query()
        ->where('status', TaskStatus::Backlog)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();
}

test('handleSort moves a task down onto an occupied position and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');

    $a = Task::factory()->backlog()->create(['sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Task::factory()->backlog()->create(['sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Task::factory()->backlog()->create(['sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::kanban')->call('handleSort', $a->id, 1, 'backlog');

    expect(backlogOrder())->toBe([$b->id, $a->id, $c->id]);
});

test('handleSort moves a task up onto an occupied position and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');

    $a = Task::factory()->backlog()->create(['sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Task::factory()->backlog()->create(['sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Task::factory()->backlog()->create(['sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::kanban')->call('handleSort', $c->id, 0, 'backlog');

    expect(backlogOrder())->toBe([$c->id, $a->id, $b->id]);
});

test('handleSort assigns unique sequential sort_order values', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');

    $a = Task::factory()->backlog()->create(['sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Task::factory()->backlog()->create(['sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Task::factory()->backlog()->create(['sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::kanban')->call('handleSort', $a->id, 2, 'backlog');

    expect(Task::whereIn('id', [$a->id, $b->id, $c->id])->pluck('sort_order')->sort()->values()->all())
        ->toBe([0, 1, 2]);
});

test('handleTaskSort reorders drill-down tasks within a feature and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');
    $feature = Feature::factory()->todo()->create();

    $a = Task::factory()->backlog()->create(['feature_id' => $feature->id, 'sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Task::factory()->backlog()->create(['feature_id' => $feature->id, 'sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Task::factory()->backlog()->create(['feature_id' => $feature->id, 'sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::features')
        ->call('enterDrill', $feature->id)
        ->call('handleTaskSort', $a->id, 1, 'backlog');

    $order = Task::query()
        ->where('feature_id', $feature->id)
        ->where('status', TaskStatus::Backlog)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    expect($order)->toBe([$b->id, $a->id, $c->id]);
});

test('project-detail handleTaskSort reorders drill-down tasks and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');
    $project = Project::factory()->create();
    $feature = Feature::factory()->todo()->create(['project_id' => $project->id]);

    $a = Task::factory()->backlog()->create(['feature_id' => $feature->id, 'sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Task::factory()->backlog()->create(['feature_id' => $feature->id, 'sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Task::factory()->backlog()->create(['feature_id' => $feature->id, 'sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('enterDrill', $feature->id)
        ->call('handleTaskSort', $a->id, 1, 'backlog');

    $order = Task::query()
        ->where('feature_id', $feature->id)
        ->where('status', TaskStatus::Backlog)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    expect($order)->toBe([$b->id, $a->id, $c->id]);
});

test('project-detail handleFeatureSort reorders features within the project and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');
    $project = Project::factory()->create();

    $a = Feature::factory()->todo()->create(['project_id' => $project->id, 'sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Feature::factory()->todo()->create(['project_id' => $project->id, 'sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Feature::factory()->todo()->create(['project_id' => $project->id, 'sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('handleFeatureSort', $a->id, 1, 'todo');

    $order = Feature::query()
        ->where('project_id', $project->id)
        ->where('status', FeatureStatus::Todo)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    expect($order)->toBe([$b->id, $a->id, $c->id]);
});

test('project-detail handleFeatureSort does not renumber features of other projects', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');
    $project = Project::factory()->create();
    $other = Project::factory()->create();

    $a = Feature::factory()->todo()->create(['project_id' => $project->id, 'sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Feature::factory()->todo()->create(['project_id' => $project->id, 'sort_order' => 1, 'created_at' => $sharedTime]);
    $foreign = Feature::factory()->todo()->create(['project_id' => $other->id, 'sort_order' => 5, 'created_at' => $sharedTime]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('handleFeatureSort', $a->id, 1, 'todo');

    expect($foreign->fresh()->sort_order)->toBe(5);
});

test('handleFeatureSort reorders features within a column and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');

    $a = Feature::factory()->todo()->create(['sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Feature::factory()->todo()->create(['sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Feature::factory()->todo()->create(['sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::features')->call('handleFeatureSort', $a->id, 1, 'todo');

    $order = Feature::query()
        ->where('status', FeatureStatus::Todo)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    expect($order)->toBe([$b->id, $a->id, $c->id]);
});
