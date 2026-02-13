<?php

use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('component renders successfully', function () {
    $task = Task::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSuccessful();
});

test('isRunning is false when no running entry exists', function () {
    $task = Task::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isRunning', false);
});

test('isRunning is true when a running entry exists', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isRunning', true);
});

test('start creates a new time entry and dispatches timer-updated', function () {
    $task = Task::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('start')
        ->assertDispatched('timer-updated');

    $entry = TimeEntry::query()->where('task_id', $task->id)->running()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->started_at)->not->toBeNull()
        ->and($entry->stopped_at)->toBeNull();
});

test('start stops all other running timers', function () {
    $task = Task::factory()->create();
    $otherTask = Task::factory()->create();
    $runningEntry = TimeEntry::factory()->running()->create(['task_id' => $otherTask->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('start');

    expect($runningEntry->fresh()->stopped_at)->not->toBeNull();
    expect(TimeEntry::query()->running()->count())->toBe(1);
    expect(TimeEntry::query()->where('task_id', $task->id)->running()->exists())->toBeTrue();
});

test('stop dispatches open-timer-notes with entry id', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('stop')
        ->assertDispatched('open-timer-notes', entryId: $entry->id)
        ->assertDispatched('timer-updated');
});

test('stop does nothing when no running entry exists', function () {
    $task = Task::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('stop')
        ->assertNotDispatched('open-timer-notes');
});

test('toggle starts timer when not running', function () {
    $task = Task::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('toggle')
        ->assertDispatched('timer-updated');

    expect(TimeEntry::query()->where('task_id', $task->id)->running()->exists())->toBeTrue();
});

test('toggle stops timer when running', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('toggle')
        ->assertDispatched('open-timer-notes', entryId: $entry->id)
        ->assertDispatched('timer-updated');
});

test('refreshes state on timer-updated event', function () {
    $task = Task::factory()->create();

    $component = Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isRunning', false);

    // Create a running entry externally
    TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    $component->dispatch('timer-updated')
        ->assertSet('isRunning', true);
});

test('renders button when not running without elapsed display', function () {
    $task = Task::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSeeHtml('data-flux-button')
        ->assertDontSeeHtml('x-text="elapsed"');
});

test('renders elapsed time display when running', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSeeHtml('x-text="elapsed"');
});
