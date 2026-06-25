<?php

use App\Models\Activity;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('component renders successfully', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSuccessful();
});

test('isRunning is false when no running entry exists', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isRunning', false);
});

test('isRunning is true when a running entry exists', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isRunning', true);
});

test('start creates a new time entry and dispatches timer-updated', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('start')
        ->assertDispatched('timer-updated');

    $entry = TimeEntry::query()->where('activity_id', $task->id)->running()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->started_at)->not->toBeNull()
        ->and($entry->stopped_at)->toBeNull();
});

test('start stops all other running timers', function () {
    $task = Activity::factory()->create();
    $otherTask = Activity::factory()->create();
    $runningEntry = TimeEntry::factory()->running()->create(['activity_id' => $otherTask->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('start');

    expect($runningEntry->fresh()->stopped_at)->not->toBeNull();
    expect(TimeEntry::query()->running()->count())->toBe(1);
    expect(TimeEntry::query()->where('activity_id', $task->id)->running()->exists())->toBeTrue();
});

test('stop dispatches open-timer-notes with entry id', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('stop')
        ->assertDispatched('open-timer-notes', entryId: $entry->id)
        ->assertDispatched('timer-updated');
});

test('stop does nothing when no running entry exists', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('stop')
        ->assertNotDispatched('open-timer-notes');
});

test('toggle starts timer when not running', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('toggle')
        ->assertDispatched('timer-updated');

    expect(TimeEntry::query()->where('activity_id', $task->id)->running()->exists())->toBeTrue();
});

test('toggle stops timer when running', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('toggle')
        ->assertDispatched('open-timer-notes', entryId: $entry->id)
        ->assertDispatched('timer-updated');
});

test('refreshes state on timer-updated event', function () {
    $task = Activity::factory()->create();

    $component = Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isRunning', false);

    // Create a running entry externally
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    $component->dispatch('timer-updated')
        ->assertSet('isRunning', true);
});

test('renders button when not running without elapsed display', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSeeHtml('data-flux-button')
        ->assertDontSeeHtml('x-text="elapsed"');
});

test('renders elapsed time display when running', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSeeHtml('x-text="elapsed"');
});

test('isFocusSession is false when no running entry exists', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isFocusSession', false);
});

test('isFocusSession is true when running entry is a focus session', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isFocusSession', true);
});

test('isFocusSession is false when running entry is not a focus session', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isFocusSession', false);
});

test('startFocus creates a focus session time entry', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('startFocus')
        ->assertDispatched('timer-updated');

    $entry = TimeEntry::query()->where('activity_id', $task->id)->running()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->is_focus_session)->toBeTrue()
        ->and($entry->started_at)->not->toBeNull()
        ->and($entry->stopped_at)->toBeNull();
});

test('startFocus stops all other running timers', function () {
    $task = Activity::factory()->create();
    $otherTask = Activity::factory()->create();
    $runningEntry = TimeEntry::factory()->running()->create(['activity_id' => $otherTask->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('startFocus');

    expect($runningEntry->fresh()->stopped_at)->not->toBeNull();
    expect(TimeEntry::query()->running()->count())->toBe(1);

    $newEntry = TimeEntry::query()->where('activity_id', $task->id)->running()->first();
    expect($newEntry->is_focus_session)->toBeTrue();
});

test('start creates a non-focus session time entry', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('start');

    $entry = TimeEntry::query()->where('activity_id', $task->id)->running()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->is_focus_session)->toBeFalse();
});

test('renders focus emoji when running a focus session', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSee('🎯');
});

test('renders focus start button when not running', function () {
    $task = Activity::factory()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->assertSeeHtml('Iniciar modo foco');
});

test('refreshState clears isFocusSession computed', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    $component = Livewire::test('timer', ['taskId' => $task->id])
        ->assertSet('isFocusSession', true);

    // Stop the entry externally
    TimeEntry::query()->running()->update(['stopped_at' => now()]);

    $component->dispatch('timer-updated')
        ->assertSet('isFocusSession', false);
});

test('start changes task status to doing', function () {
    $task = Activity::factory()->todo()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('start');

    $task->refresh();
    expect($task->status)->toBe(\App\Enums\ActivityStatus::Doing);
});

test('startFocus changes task status to doing', function () {
    $task = Activity::factory()->todo()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('startFocus');

    $task->refresh();
    expect($task->status)->toBe(\App\Enums\ActivityStatus::Doing);
});

test('start does not change status if task is already doing', function () {
    $task = Activity::factory()->doing()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('start');

    $task->refresh();
    expect($task->status)->toBe(\App\Enums\ActivityStatus::Doing);
});

test('start does not change status if task is done', function () {
    $task = Activity::factory()->done()->create();

    Livewire::test('timer', ['taskId' => $task->id])
        ->call('start');

    $task->refresh();
    expect($task->status)->toBe(\App\Enums\ActivityStatus::Done);
});
