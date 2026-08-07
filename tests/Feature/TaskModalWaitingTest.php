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

test('selecting a client-side wait status auto-fills waiting_for from the effective client', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create(['project_id' => $project->id, 'status' => ActivityStatus::Todo]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('status', 'awaiting_approval')
        ->assertSet('waitingFor', 'Acme Corp')
        ->call('saveTask');

    expect($task->fresh()->status)->toBe(ActivityStatus::AwaitingApproval)
        ->and($task->fresh()->waiting_for)->toBe('Acme Corp');
});

test('selecting the internal wait status with no name opens the blocking prompt and does not save', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Doing]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('status', 'waiting')
        ->assertSet('showWaitingForPrompt', true)
        ->call('saveTask')
        ->assertSet('showWaitingForPrompt', true);

    expect($task->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('filling the prompt and confirming saves the internal wait', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Doing]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('status', 'waiting')
        ->set('waitingFor', 'Designer')
        ->call('saveTask')
        ->assertSet('showWaitingForPrompt', false);

    expect($task->fresh()->status)->toBe(ActivityStatus::Waiting)
        ->and($task->fresh()->waiting_for)->toBe('Designer');
});

test('the task modal shows the waiting badge with days and quem while in a wait', function () {
    $task = Activity::factory()->waiting('Designer')->create();
    $task->forceFill(['waiting_since' => now()->subDays(5)])->saveQuietly();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSet('waitingFor', 'Designer')
        ->assertSet('waitingDays', 5)
        ->assertSeeText('Designer')
        ->assertSeeText('5 dias');
});

test('leaving a wait status via the task modal clears waiting_for', function () {
    $task = Activity::factory()->waiting('Designer')->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('status', 'doing')
        ->call('saveTask');

    expect($task->fresh()->waiting_for)->toBeNull()
        ->and($task->fresh()->waiting_since)->toBeNull();
});
