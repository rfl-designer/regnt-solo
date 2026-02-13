<?php

use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('component renders successfully', function () {
    Livewire::test('timer-notes-modal')
        ->assertSuccessful();
});

test('opens modal when open-timer-notes event is dispatched', function () {
    $task = Task::factory()->create(['title' => 'My Task']);
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSet('showModal', true)
        ->assertSet('entryId', $entry->id)
        ->assertSet('taskName', 'My Task')
        ->assertSet('notes', '');
});

test('clears notes when opening modal', function () {
    $task = Task::factory()->create();
    $entry1 = TimeEntry::factory()->running()->create(['task_id' => $task->id]);
    $entry2 = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry1->id)
        ->set('notes', 'Some notes')
        ->dispatch('open-timer-notes', entryId: $entry2->id)
        ->assertSet('notes', '');
});

test('saveWithNotes stops timer and saves notes', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->set('notes', 'Worked on feature X')
        ->call('saveWithNotes')
        ->assertSet('showModal', false)
        ->assertDispatched('timer-updated');

    $entry->refresh();
    expect($entry->stopped_at)->not->toBeNull();
    expect($entry->notes)->toBe('Worked on feature X');
});

test('skipNotes stops timer without notes', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create([
        'task_id' => $task->id,
        'notes' => null,
    ]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->call('skipNotes')
        ->assertSet('showModal', false)
        ->assertDispatched('timer-updated');

    $entry->refresh();
    expect($entry->stopped_at)->not->toBeNull();
    expect($entry->notes)->toBeNull();
});

test('dispatches timer-updated event on saveWithNotes', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->set('notes', 'Done')
        ->call('saveWithNotes')
        ->assertDispatched('timer-updated');
});

test('dispatches timer-updated event on skipNotes', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->call('skipNotes')
        ->assertDispatched('timer-updated');
});

test('modal closes after saveWithNotes', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->call('saveWithNotes')
        ->assertSet('showModal', false);
});

test('modal closes after skipNotes', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->call('skipNotes')
        ->assertSet('showModal', false);
});

test('displays task name in modal', function () {
    $task = Task::factory()->create(['title' => 'Implement feature ABC']);
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSee('Implement feature ABC');
});
