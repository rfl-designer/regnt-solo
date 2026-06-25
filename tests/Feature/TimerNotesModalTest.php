<?php

use App\Models\Activity;
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
    $task = Activity::factory()->create(['title' => 'My Task']);
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSet('showModal', true)
        ->assertSet('entryId', $entry->id)
        ->assertSet('itemName', 'My Task')
        ->assertSet('itemType', 'task')
        ->assertSet('notes', '');
});

test('clears notes when opening modal', function () {
    $task = Activity::factory()->create();
    $entry1 = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);
    $entry2 = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry1->id)
        ->set('notes', 'Some notes')
        ->dispatch('open-timer-notes', entryId: $entry2->id)
        ->assertSet('notes', '');
});

test('saveWithNotes stops timer and saves notes', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

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
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create([
        'activity_id' => $task->id,
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
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->set('notes', 'Done')
        ->call('saveWithNotes')
        ->assertDispatched('timer-updated');
});

test('dispatches timer-updated event on skipNotes', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->call('skipNotes')
        ->assertDispatched('timer-updated');
});

test('modal closes after saveWithNotes', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->call('saveWithNotes')
        ->assertSet('showModal', false);
});

test('modal closes after skipNotes', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->call('skipNotes')
        ->assertSet('showModal', false);
});

test('displays task name in modal', function () {
    $task = Activity::factory()->create(['title' => 'Implement feature ABC']);
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSee('Implement feature ABC');
});

test('opens modal with isFocusSession true for focus entries', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSet('isFocusSession', true)
        ->assertSet('focusRating', null);
});

test('opens modal with isFocusSession false for normal entries', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSet('isFocusSession', false);
});

test('displays focus emoji for focus session entries', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSee('🎯');
});

test('displays focus rating stars for focus session entries', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSee('Como foi a sessão?')
        ->assertSee('⭐');
});

test('does not display focus rating for normal entries', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertDontSee('Como foi a sessão?');
});

test('saveWithNotes saves focus rating for focus sessions', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->set('focusRating', 4)
        ->set('notes', 'Great focus session')
        ->call('saveWithNotes')
        ->assertSet('showModal', false)
        ->assertDispatched('timer-updated');

    $entry->refresh();
    expect($entry->stopped_at)->not->toBeNull()
        ->and($entry->notes)->toBe('Great focus session')
        ->and($entry->focus_rating)->toBe(4);
});

test('saveWithNotes does not save focus rating when not set', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->set('notes', 'No rating given')
        ->call('saveWithNotes');

    $entry->refresh();
    expect($entry->focus_rating)->toBeNull();
});

test('skipNotes saves focus rating for focus sessions when set', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id, 'notes' => null]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->set('focusRating', 3)
        ->call('skipNotes')
        ->assertSet('showModal', false)
        ->assertDispatched('timer-updated');

    $entry->refresh();
    expect($entry->stopped_at)->not->toBeNull()
        ->and($entry->focus_rating)->toBe(3)
        ->and($entry->notes)->toBeNull();
});

test('skipNotes does not save focus rating when not set', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->call('skipNotes');

    $entry->refresh();
    expect($entry->focus_rating)->toBeNull();
});

test('resets focusRating when opening a new entry', function () {
    $task = Activity::factory()->create();
    $entry1 = TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);
    $entry2 = TimeEntry::factory()->running()->focus()->create(['activity_id' => $task->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry1->id)
        ->set('focusRating', 5)
        ->dispatch('open-timer-notes', entryId: $entry2->id)
        ->assertSet('focusRating', null);
});

test('opens modal with feature title when entry belongs to a feature', function () {
    $feature = \App\Models\Activity::factory()->epic()->create(['title' => 'Epic Feature']);
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $feature->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSet('showModal', true)
        ->assertSet('entryId', $entry->id)
        ->assertSet('itemName', 'Epic Feature')
        ->assertSet('itemType', 'feature')
        ->assertSet('notes', '');
});

test('displays feature name in modal for feature entries', function () {
    $feature = \App\Models\Activity::factory()->epic()->create(['title' => 'Implement Dark Mode']);
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $feature->id]);

    Livewire::test('timer-notes-modal')
        ->dispatch('open-timer-notes', entryId: $entry->id)
        ->assertSee('Implement Dark Mode');
});
