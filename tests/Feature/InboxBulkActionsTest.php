<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(\App\Models\User::factory()->create());
});

test('can select all tasks', function () {
    Activity::factory()->count(3)->create();

    Livewire::test('pages::inbox')
        ->call('selectAll')
        ->assertSet('selectedTasks', Activity::inbox()->pluck('id')->map(fn ($id) => (int) $id)->all());
});

test('can deselect all tasks', function () {
    $tasks = Activity::factory()->count(3)->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', $tasks->pluck('id')->all())
        ->call('deselectAll')
        ->assertSet('selectedTasks', []);
});

test('bulk move to status moves selected tasks', function () {
    $task1 = Activity::factory()->create(['title' => 'Task 1']);
    $task2 = Activity::factory()->create(['title' => 'Task 2']);
    $task3 = Activity::factory()->create(['title' => 'Task 3']);

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task1->id, $task2->id])
        ->call('bulkMoveToStatus', ActivityStatus::Backlog->value);

    expect($task1->fresh()->status)->toBe(ActivityStatus::Backlog)
        ->and($task2->fresh()->status)->toBe(ActivityStatus::Backlog)
        ->and($task3->fresh()->status)->toBe(ActivityStatus::Inbox);
});

test('bulk move to done sets completed_at', function () {
    $task = Activity::factory()->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task->id])
        ->call('bulkMoveToStatus', ActivityStatus::Done->value);

    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Done)
        ->and($task->completed_at)->not->toBeNull();
});

test('bulk move clears selection after action', function () {
    $tasks = Activity::factory()->count(2)->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', $tasks->pluck('id')->all())
        ->call('bulkMoveToStatus', ActivityStatus::Todo->value)
        ->assertSet('selectedTasks', []);
});

test('bulk delete removes selected tasks', function () {
    $task1 = Activity::factory()->create(['title' => 'Task 1']);
    $task2 = Activity::factory()->create(['title' => 'Task 2']);
    $task3 = Activity::factory()->create(['title' => 'Task 3']);

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task1->id, $task2->id])
        ->call('bulkDelete');

    expect(Activity::find($task1->id))->toBeNull()
        ->and(Activity::find($task2->id))->toBeNull()
        ->and(Activity::find($task3->id))->not->toBeNull();
});

test('bulk delete clears selection after action', function () {
    $tasks = Activity::factory()->count(2)->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', $tasks->pluck('id')->all())
        ->call('bulkDelete')
        ->assertSet('selectedTasks', [])
        ->assertSet('showBulkDeleteModal', false);
});

test('confirm bulk delete opens modal', function () {
    $task = Activity::factory()->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task->id])
        ->call('confirmBulkDelete')
        ->assertSet('showBulkDeleteModal', true);
});

test('bulk actions only affect inbox tasks', function () {
    $inboxTask = Activity::factory()->create();
    $backlogTask = Activity::factory()->backlog()->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$inboxTask->id, $backlogTask->id])
        ->call('bulkMoveToStatus', ActivityStatus::Todo->value);

    expect($inboxTask->fresh()->status)->toBe(ActivityStatus::Todo)
        ->and($backlogTask->fresh()->status)->toBe(ActivityStatus::Backlog);
});

test('bulk actions bar shows when tasks are selected', function () {
    $task = Activity::factory()->create(['title' => 'Task selecionada']);

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task->id])
        ->assertSee('1 task selecionada')
        ->assertSee('Mover para')
        ->assertSee('Limpar seleção');
});

test('bulk actions bar shows plural when multiple tasks selected', function () {
    $tasks = Activity::factory()->count(3)->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', $tasks->pluck('id')->all())
        ->assertSee('3 tasks selecionadas');
});

test('checkbox select all header is rendered', function () {
    Activity::factory()->create();

    Livewire::test('pages::inbox')
        ->assertSee('selectAll');
});
