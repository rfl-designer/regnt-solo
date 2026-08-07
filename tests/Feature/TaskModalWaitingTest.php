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

// -----------------------------------------------------------------------
// Finding 4: switching between two waiting categories in the modal must
// not silently reuse the previous wait's "esperando quem".
// -----------------------------------------------------------------------

test('switching from a client-side wait to the internal wait clears the inherited name and opens the prompt', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Acme Corp',
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSet('waitingFor', 'Acme Corp')
        ->set('status', 'waiting')
        ->assertSet('waitingFor', '')
        ->assertSet('showWaitingForPrompt', true)
        ->call('saveTask')
        ->assertSet('showWaitingForPrompt', true);

    expect($task->fresh()->status)->toBe(ActivityStatus::AwaitingApproval);
});

test('switching from the internal wait to a client-side wait re-resolves the effective client', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::Waiting,
        'waiting_for' => 'Designer',
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSet('waitingFor', 'Designer')
        ->set('status', 'awaiting_validation')
        ->assertSet('waitingFor', 'Acme Corp')
        ->call('saveTask');

    expect($task->fresh()->status)->toBe(ActivityStatus::AwaitingValidation)
        ->and($task->fresh()->waiting_for)->toBe('Acme Corp');
});

test('re-selecting the status the task already had keeps the stored waiting_for untouched', function () {
    $task = Activity::factory()->waiting('Designer')->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('status', 'doing')
        ->set('status', 'waiting')
        ->assertSet('waitingFor', 'Designer')
        ->assertSet('showWaitingForPrompt', false);
});

// -----------------------------------------------------------------------
// Finding 5: changing project/client during a client-side wait must
// re-resolve "esperando quem" in the modal too.
// -----------------------------------------------------------------------

test('changing the project while already in a client-side wait re-resolves waitingFor in the modal', function () {
    $oldClient = Client::factory()->create(['name' => 'Old Client']);
    $oldProject = Project::factory()->create(['client_id' => $oldClient->id]);
    $newClient = Client::factory()->create(['name' => 'New Client']);
    $newProject = Project::factory()->create(['client_id' => $newClient->id]);

    $task = Activity::factory()->create([
        'project_id' => $oldProject->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Old Client',
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSet('waitingFor', 'Old Client')
        ->set('projectId', (string) $newProject->id)
        ->assertSet('waitingFor', 'New Client')
        ->call('saveTask');

    expect($task->fresh()->waiting_for)->toBe('New Client');
});

test('manually editing waitingFor stops a later project change from overwriting it', function () {
    $oldClient = Client::factory()->create(['name' => 'Old Client']);
    $oldProject = Project::factory()->create(['client_id' => $oldClient->id]);
    $newProject = Project::factory()->create();

    $task = Activity::factory()->create([
        'project_id' => $oldProject->id,
        'status' => ActivityStatus::AwaitingApproval,
        'waiting_for' => 'Old Client',
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('waitingFor', 'Manually set name')
        ->set('projectId', (string) $newProject->id)
        ->assertSet('waitingFor', 'Manually set name')
        ->call('saveTask');

    expect($task->fresh()->waiting_for)->toBe('Manually set name');
});
