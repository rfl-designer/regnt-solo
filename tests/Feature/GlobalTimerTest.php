<?php

use App\Models\Activity;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('component renders successfully', function () {
    Livewire::test('global-timer')
        ->assertSuccessful();
});

test('does not show anything when no timer is active', function () {
    Livewire::test('global-timer')
        ->assertSet('activeEntry', null)
        ->assertDontSeeHtml('x-text="elapsed"');
});

test('shows task title when timer is active', function () {
    $task = Activity::factory()->create(['title' => 'My Active Task']);
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('global-timer')
        ->assertSee('My Active Task');
});

test('stop dispatches open-timer-notes and timer-updated', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('global-timer')
        ->call('stop')
        ->assertDispatched('open-timer-notes', entryId: $entry->id)
        ->assertDispatched('timer-updated');
});

test('stop does nothing when no running entry exists', function () {
    Livewire::test('global-timer')
        ->call('stop')
        ->assertNotDispatched('open-timer-notes')
        ->assertNotDispatched('timer-updated');
});

test('refreshes state on timer-updated event', function () {
    $component = Livewire::test('global-timer')
        ->assertSet('activeEntry', null);

    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    $component->dispatch('timer-updated')
        ->assertSet('activeEntry.activity_id', $task->id);
});

test('renders elapsed time display when timer is active', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('global-timer')
        ->assertSeeHtml('x-text="elapsed"');
});

test('renders stop button when timer is active', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('global-timer')
        ->assertSeeHtml('data-flux-button');
});

test('shows focus emoji when running a focus session', function () {
    $task = Activity::factory()->create(['title' => 'Focus Task']);
    TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('global-timer')
        ->assertSee('🎯')
        ->assertSee('Focus Task');
});

test('shows timer emoji when running a normal session', function () {
    $task = Activity::factory()->create(['title' => 'Normal Task']);
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('global-timer')
        ->assertSee('⏱')
        ->assertSee('Normal Task');
});

test('applies amber styling for focus session task title', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('global-timer')
        ->assertSeeHtml('text-amber-400');
});

test('does not apply amber styling for normal session', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('global-timer')
        ->assertDontSeeHtml('text-amber-400');
});
