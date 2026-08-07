<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('dragging a card into a client-side wait auto-fills waiting_for and moves it', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->issue()->create(['project_id' => $project->id, 'status' => ActivityStatus::Todo]);

    Livewire::test('pages::kanban')->call('handleSort', $task->id, 0, 'awaiting_approval');

    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::AwaitingApproval)
        ->and($task->waiting_for)->toBe('Acme Corp')
        ->and($task->waiting_since)->not->toBeNull();
});

test('dragging a card with no effective client into a client-side wait is refused with a toast, not persisted', function () {
    $task = Activity::factory()->issue()->create(['status' => ActivityStatus::Todo]);

    Livewire::test('pages::kanban')->call('handleSort', $task->id, 0, 'awaiting_approval');

    expect($task->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('dragging a card into the internal wait defers to the blocking mini modal instead of persisting immediately', function () {
    $task = Activity::factory()->issue()->create(['status' => ActivityStatus::Doing]);

    Livewire::test('pages::kanban')
        ->call('handleSort', $task->id, 0, 'waiting')
        ->assertDispatched('open-waiting-for-modal', taskId: $task->id, status: 'waiting');

    expect($task->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('the waiting-for modal confirms the move with the given name', function () {
    $task = Activity::factory()->issue()->create(['status' => ActivityStatus::Doing]);

    Livewire::test('waiting-for-modal')
        ->dispatch('open-waiting-for-modal', taskId: $task->id, status: 'waiting')
        ->set('waitingFor', 'DevOps')
        ->call('confirm')
        ->assertDispatched('task-updated');

    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Waiting)
        ->and($task->waiting_for)->toBe('DevOps')
        ->and($task->waiting_since)->not->toBeNull();
});

test('the waiting-for modal requires a name before confirming', function () {
    $task = Activity::factory()->issue()->create(['status' => ActivityStatus::Doing]);

    Livewire::test('waiting-for-modal')
        ->dispatch('open-waiting-for-modal', taskId: $task->id, status: 'waiting')
        ->set('waitingFor', '')
        ->call('confirm')
        ->assertHasErrors(['waitingFor']);

    expect($task->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('the kanban board renders all 7 columns in flow order with a waiting badge on waiting cards', function () {
    $task = Activity::factory()->issue()->waiting('Designer')->create();

    Livewire::test('pages::kanban')
        ->assertSeeInOrder([
            ActivityStatus::Backlog->label(),
            ActivityStatus::AwaitingApproval->label(),
            ActivityStatus::Todo->label(),
            ActivityStatus::Doing->label(),
            ActivityStatus::Waiting->label(),
            ActivityStatus::AwaitingValidation->label(),
            ActivityStatus::Done->label(),
        ])
        ->assertSeeText('Designer');

    expect($task->waitingDays())->toBe(0);
});
