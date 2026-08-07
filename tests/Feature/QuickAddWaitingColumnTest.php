<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('opening quick add from the awaiting_approval column still creates in inbox', function () {
    Livewire::test('task-quick-add')
        ->dispatch('open-quick-add-with-status', status: 'awaiting_approval')
        ->assertSet('initialStatus', 'awaiting_approval')
        ->set('rawInput', 'Tarefa via coluna de espera')
        ->call('createTask')
        ->assertHasNoErrors();

    $task = Activity::where('title', 'Tarefa via coluna de espera')->firstOrFail();

    expect($task->status)->toBe(ActivityStatus::Inbox)
        ->and($task->waiting_for)->toBeNull();
});

test('opening quick add from the internal wait column does not throw and creates in inbox', function () {
    Livewire::test('task-quick-add')
        ->dispatch('open-quick-add-with-status', status: 'waiting')
        ->set('rawInput', 'Tarefa via esperando')
        ->call('createTask')
        ->assertHasNoErrors();

    $task = Activity::where('title', 'Tarefa via esperando')->firstOrFail();

    expect($task->status)->toBe(ActivityStatus::Inbox)
        ->and($task->waiting_for)->toBeNull();
});

test('opening quick add from the awaiting_validation column does not throw and creates in inbox', function () {
    Livewire::test('task-quick-add')
        ->dispatch('open-quick-add-with-status', status: 'awaiting_validation')
        ->set('rawInput', 'Tarefa via aguardando validacao')
        ->call('createTask')
        ->assertHasNoErrors();

    $task = Activity::where('title', 'Tarefa via aguardando validacao')->firstOrFail();

    expect($task->status)->toBe(ActivityStatus::Inbox);
});
