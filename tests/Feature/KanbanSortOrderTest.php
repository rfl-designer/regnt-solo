<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
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
    return Activity::query()
        ->where('status', ActivityStatus::Backlog)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();
}

test('handleSort moves a task down onto an occupied position and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');

    $a = Activity::factory()->issue()->backlog()->create(['sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Activity::factory()->issue()->backlog()->create(['sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Activity::factory()->issue()->backlog()->create(['sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::kanban')->call('handleSort', $a->id, 1, 'backlog');

    expect(backlogOrder())->toBe([$b->id, $a->id, $c->id]);
});

test('handleSort moves a task up onto an occupied position and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');

    $a = Activity::factory()->issue()->backlog()->create(['sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Activity::factory()->issue()->backlog()->create(['sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Activity::factory()->issue()->backlog()->create(['sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::kanban')->call('handleSort', $c->id, 0, 'backlog');

    expect(backlogOrder())->toBe([$c->id, $a->id, $b->id]);
});

test('handleSort assigns unique sequential sort_order values', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');

    $a = Activity::factory()->issue()->backlog()->create(['sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Activity::factory()->issue()->backlog()->create(['sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Activity::factory()->issue()->backlog()->create(['sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::kanban')->call('handleSort', $a->id, 2, 'backlog');

    expect(Activity::whereIn('id', [$a->id, $b->id, $c->id])->pluck('sort_order')->sort()->values()->all())
        ->toBe([0, 1, 2]);
});

test('project-detail handleTaskSort reorders drill-down tasks and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');
    $project = Project::factory()->create();
    $feature = Activity::factory()->epic()->todo()->create(['project_id' => $project->id]);

    $a = Activity::factory()->backlog()->create(['parent_id' => $feature->id, 'sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Activity::factory()->backlog()->create(['parent_id' => $feature->id, 'sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Activity::factory()->backlog()->create(['parent_id' => $feature->id, 'sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('enterDrill', $feature->id)
        ->call('handleTaskSort', $a->id, 1, 'backlog');

    $order = Activity::query()
        ->where('parent_id', $feature->id)
        ->where('status', ActivityStatus::Backlog)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    expect($order)->toBe([$b->id, $a->id, $c->id]);
});

test('project-detail handleFeatureSort reorders features within the project and persists order', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');
    $project = Project::factory()->create();

    $a = Activity::factory()->epic()->todo()->create(['project_id' => $project->id, 'sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Activity::factory()->epic()->todo()->create(['project_id' => $project->id, 'sort_order' => 1, 'created_at' => $sharedTime]);
    $c = Activity::factory()->epic()->todo()->create(['project_id' => $project->id, 'sort_order' => 2, 'created_at' => $sharedTime]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('handleFeatureSort', $a->id, 1, 'todo');

    $order = Activity::query()->epics()
        ->where('project_id', $project->id)
        ->where('status', ActivityStatus::Todo)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    expect($order)->toBe([$b->id, $a->id, $c->id]);
});

test('project-detail handleFeatureSort does not renumber features of other projects', function () {
    $sharedTime = Carbon::parse('2026-02-15 22:39:43');
    $project = Project::factory()->create();
    $other = Project::factory()->create();

    $a = Activity::factory()->epic()->todo()->create(['project_id' => $project->id, 'sort_order' => 0, 'created_at' => $sharedTime]);
    $b = Activity::factory()->epic()->todo()->create(['project_id' => $project->id, 'sort_order' => 1, 'created_at' => $sharedTime]);
    $foreign = Activity::factory()->epic()->todo()->create(['project_id' => $other->id, 'sort_order' => 5, 'created_at' => $sharedTime]);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->call('handleFeatureSort', $a->id, 1, 'todo');

    expect($foreign->fresh()->sort_order)->toBe(5);
});
