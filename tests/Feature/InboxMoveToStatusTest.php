<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('move to status changes task to backlog', function () {
    $task = Activity::factory()->create(['title' => 'Task para backlog']);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'backlog')
        ->assertDispatched('task-moved');

    expect($task->fresh())
        ->status->toBe(ActivityStatus::Backlog)
        ->completed_at->toBeNull();
});

test('move to status changes task to todo', function () {
    $task = Activity::factory()->create(['title' => 'Task para todo']);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'todo')
        ->assertDispatched('task-moved');

    expect($task->fresh())
        ->status->toBe(ActivityStatus::Todo)
        ->completed_at->toBeNull();
});

test('move to status changes task to doing', function () {
    $task = Activity::factory()->create(['title' => 'Task para doing']);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'doing')
        ->assertDispatched('task-moved');

    expect($task->fresh())
        ->status->toBe(ActivityStatus::Doing)
        ->completed_at->toBeNull();
});

test('move to status changes task to done and sets completed_at', function () {
    $task = Activity::factory()->create(['title' => 'Task para done']);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'done')
        ->assertDispatched('task-moved');

    expect($task->fresh())
        ->status->toBe(ActivityStatus::Done)
        ->completed_at->not->toBeNull();
});

test('move to status only works for inbox tasks', function () {
    $task = Activity::factory()->backlog()->create();

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'todo');
});

test('move to status removes task from inbox list', function () {
    $task = Activity::factory()->create(['title' => 'Task que vai sumir']);

    $component = Livewire::test('pages::inbox')
        ->assertSee('Task que vai sumir')
        ->call('moveToStatus', $task->id, 'backlog');

    $component->assertDontSee('Task que vai sumir');
});

test('available statuses returns all statuses except inbox', function () {
    $component = Livewire::test('pages::inbox');

    $statuses = $component->instance()->availableStatuses();

    expect($statuses)
        ->toHaveCount(4)
        ->toContain(ActivityStatus::Backlog)
        ->toContain(ActivityStatus::Todo)
        ->toContain(ActivityStatus::Doing)
        ->toContain(ActivityStatus::Done)
        ->not->toContain(ActivityStatus::Inbox);
});

test('inbox page shows move dropdown for each task', function () {
    Activity::factory()->create(['title' => 'Task com dropdown']);

    Livewire::test('pages::inbox')
        ->assertSee('Mover');
});
