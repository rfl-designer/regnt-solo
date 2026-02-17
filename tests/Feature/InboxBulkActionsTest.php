<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(\App\Models\User::factory()->create());
});

test('can select all tasks', function () {
    Task::factory()->count(3)->create();

    Livewire::test('pages::inbox')
        ->call('selectAll')
        ->assertSet('selectedTasks', Task::inbox()->pluck('id')->map(fn ($id) => (int) $id)->all());
});

test('can deselect all tasks', function () {
    $tasks = Task::factory()->count(3)->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', $tasks->pluck('id')->all())
        ->call('deselectAll')
        ->assertSet('selectedTasks', []);
});

test('bulk move to status moves selected tasks', function () {
    $task1 = Task::factory()->create(['title' => 'Task 1']);
    $task2 = Task::factory()->create(['title' => 'Task 2']);
    $task3 = Task::factory()->create(['title' => 'Task 3']);

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task1->id, $task2->id])
        ->call('bulkMoveToStatus', TaskStatus::Backlog->value);

    expect($task1->fresh()->status)->toBe(TaskStatus::Backlog)
        ->and($task2->fresh()->status)->toBe(TaskStatus::Backlog)
        ->and($task3->fresh()->status)->toBe(TaskStatus::Inbox);
});

test('bulk move to done sets completed_at', function () {
    $task = Task::factory()->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task->id])
        ->call('bulkMoveToStatus', TaskStatus::Done->value);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at)->not->toBeNull();
});

test('bulk move clears selection after action', function () {
    $tasks = Task::factory()->count(2)->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', $tasks->pluck('id')->all())
        ->call('bulkMoveToStatus', TaskStatus::Todo->value)
        ->assertSet('selectedTasks', []);
});

test('bulk delete removes selected tasks', function () {
    $task1 = Task::factory()->create(['title' => 'Task 1']);
    $task2 = Task::factory()->create(['title' => 'Task 2']);
    $task3 = Task::factory()->create(['title' => 'Task 3']);

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task1->id, $task2->id])
        ->call('bulkDelete');

    expect(Task::find($task1->id))->toBeNull()
        ->and(Task::find($task2->id))->toBeNull()
        ->and(Task::find($task3->id))->not->toBeNull();
});

test('bulk delete clears selection after action', function () {
    $tasks = Task::factory()->count(2)->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', $tasks->pluck('id')->all())
        ->call('bulkDelete')
        ->assertSet('selectedTasks', [])
        ->assertSet('showBulkDeleteModal', false);
});

test('confirm bulk delete opens modal', function () {
    $task = Task::factory()->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task->id])
        ->call('confirmBulkDelete')
        ->assertSet('showBulkDeleteModal', true);
});

test('bulk actions only affect inbox tasks', function () {
    $inboxTask = Task::factory()->create();
    $backlogTask = Task::factory()->backlog()->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$inboxTask->id, $backlogTask->id])
        ->call('bulkMoveToStatus', TaskStatus::Todo->value);

    expect($inboxTask->fresh()->status)->toBe(TaskStatus::Todo)
        ->and($backlogTask->fresh()->status)->toBe(TaskStatus::Backlog);
});

test('bulk actions bar shows when tasks are selected', function () {
    $task = Task::factory()->create(['title' => 'Task selecionada']);

    Livewire::test('pages::inbox')
        ->set('selectedTasks', [$task->id])
        ->assertSee('1 task selecionada')
        ->assertSee('Mover para')
        ->assertSee('Limpar seleção');
});

test('bulk actions bar shows plural when multiple tasks selected', function () {
    $tasks = Task::factory()->count(3)->create();

    Livewire::test('pages::inbox')
        ->set('selectedTasks', $tasks->pluck('id')->all())
        ->assertSee('3 tasks selecionadas');
});

test('checkbox select all header is rendered', function () {
    Task::factory()->create();

    Livewire::test('pages::inbox')
        ->assertSee('selectAll');
});
