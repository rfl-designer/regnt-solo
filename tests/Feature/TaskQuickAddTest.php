<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('component renders successfully', function () {
    Livewire::test('task-quick-add')
        ->assertSuccessful()
        ->assertSee('Nova Task');
});

test('can create a task with a simple title', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Minha nova tarefa')
        ->call('createTask');

    expect(Task::count())->toBe(1);

    $task = Task::first();
    expect($task)
        ->title->toBe('Minha nova tarefa')
        ->status->toBe(TaskStatus::Inbox)
        ->priority->toBe(TaskPriority::Medium)
        ->project_id->toBeNull()
        ->due_date->toBeNull();
});

test('can create a task with full inline syntax', function () {
    $project = Project::factory()->create(['slug' => 'meu-projeto']);

    Livewire::test('task-quick-add')
        ->set('rawInput', 'Revisar PR #meu-projeto !high @hoje')
        ->call('createTask');

    expect(Task::count())->toBe(1);

    $task = Task::first();
    expect($task)
        ->title->toBe('Revisar PR')
        ->project_id->toBe($project->id)
        ->priority->toBe(TaskPriority::High)
        ->due_date->toEqual(now()->startOfDay());
});

test('invalid project slug creates task without project and shows warning', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa #projeto-inexistente')
        ->call('createTask');

    expect(Task::count())->toBe(1);

    $task = Task::first();
    expect($task)
        ->title->toBe('Tarefa')
        ->project_id->toBeNull();
});

test('input resets after creating a task', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Minha tarefa')
        ->call('createTask')
        ->assertSet('rawInput', '')
        ->assertSet('activePrefix', '')
        ->assertSet('prefixSearch', '');
});

test('task is always created with inbox status', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa inbox')
        ->call('createTask');

    $task = Task::first();
    expect($task->status)->toBe(TaskStatus::Inbox);
});

test('does not create task with empty input', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', '')
        ->call('createTask');

    expect(Task::count())->toBe(0);
});

test('does not create task when only tokens are provided', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', '#projeto !high @hoje')
        ->call('createTask');

    expect(Task::count())->toBe(0);
});

test('detects active prefix when typing hash', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa #me')
        ->assertSet('activePrefix', '#')
        ->assertSet('prefixSearch', 'me');
});

test('detects active prefix when typing exclamation', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa !ur')
        ->assertSet('activePrefix', '!')
        ->assertSet('prefixSearch', 'ur');
});

test('detects active prefix when typing at sign', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa @ho')
        ->assertSet('activePrefix', '@')
        ->assertSet('prefixSearch', 'ho');
});

test('clears active prefix when no prefix is being typed', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa #projeto ')
        ->assertSet('activePrefix', '')
        ->assertSet('prefixSearch', '');
});

test('select suggestion replaces partial token in input', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa #me')
        ->call('selectSuggestion', 'meu-projeto')
        ->assertSet('rawInput', 'Tarefa #meu-projeto ')
        ->assertSet('activePrefix', '')
        ->assertSet('prefixSearch', '');
});

test('can create task with date alias amanha', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa @amanha')
        ->call('createTask');

    $task = Task::first();
    expect($task->due_date)->toEqual(now()->addDay()->startOfDay());
});

test('can create task with priority urgent', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa urgente !urgent')
        ->call('createTask');

    $task = Task::first();
    expect($task->priority)->toBe(TaskPriority::Urgent);
});
