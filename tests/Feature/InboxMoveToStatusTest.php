<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('move to status changes task to backlog', function () {
    $task = Task::factory()->create(['title' => 'Task para backlog']);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'backlog')
        ->assertDispatched('task-moved');

    expect($task->fresh())
        ->status->toBe(TaskStatus::Backlog)
        ->completed_at->toBeNull();
});

test('move to status changes task to todo', function () {
    $task = Task::factory()->create(['title' => 'Task para todo']);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'todo')
        ->assertDispatched('task-moved');

    expect($task->fresh())
        ->status->toBe(TaskStatus::Todo)
        ->completed_at->toBeNull();
});

test('move to status changes task to doing', function () {
    $task = Task::factory()->create(['title' => 'Task para doing']);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'doing')
        ->assertDispatched('task-moved');

    expect($task->fresh())
        ->status->toBe(TaskStatus::Doing)
        ->completed_at->toBeNull();
});

test('move to status changes task to done and sets completed_at', function () {
    $task = Task::factory()->create(['title' => 'Task para done']);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'done')
        ->assertDispatched('task-moved');

    expect($task->fresh())
        ->status->toBe(TaskStatus::Done)
        ->completed_at->not->toBeNull();
});

test('move to status only works for inbox tasks', function () {
    $task = Task::factory()->backlog()->create();

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'todo');
});

test('move to status removes task from inbox list', function () {
    $task = Task::factory()->create(['title' => 'Task que vai sumir']);

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
        ->toContain(TaskStatus::Backlog)
        ->toContain(TaskStatus::Todo)
        ->toContain(TaskStatus::Doing)
        ->toContain(TaskStatus::Done)
        ->not->toContain(TaskStatus::Inbox);
});

test('inbox page shows move dropdown for each task', function () {
    Task::factory()->create(['title' => 'Task com dropdown']);

    Livewire::test('pages::inbox')
        ->assertSee('Mover');
});
