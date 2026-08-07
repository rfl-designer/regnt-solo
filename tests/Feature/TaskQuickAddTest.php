<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Models\Project;
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

    expect(Activity::count())->toBe(1);

    $task = Activity::first();
    expect($task)
        ->title->toBe('Minha nova tarefa')
        ->type->toBe(ActivityType::Task)
        ->status->toBe(ActivityStatus::Inbox)
        ->service_class->toBe(ServiceClass::Standard)
        ->project_id->toBeNull()
        ->due_date->toBeNull();
});

test('can create a task with full inline syntax', function () {
    $project = Project::factory()->create(['slug' => 'meu-projeto']);

    Livewire::test('task-quick-add')
        ->set('rawInput', 'Revisar PR #meu-projeto !intangible @hoje')
        ->call('createTask');

    expect(Activity::count())->toBe(1);

    $task = Activity::first();
    expect($task)
        ->title->toBe('Revisar PR')
        ->project_id->toBe($project->id)
        ->service_class->toBe(ServiceClass::Intangible)
        ->due_date->toEqual(now()->startOfDay());
});

test('invalid project slug creates task without project and shows warning', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa #projeto-inexistente')
        ->call('createTask');

    expect(Activity::count())->toBe(1);

    $task = Activity::first();
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

test('task defaults to inbox status when no initial status is set', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa inbox')
        ->call('createTask');

    $task = Activity::first();
    expect($task->status)->toBe(ActivityStatus::Inbox);
});

test('does not create task with empty input', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', '')
        ->call('createTask');

    expect(Activity::count())->toBe(0);
});

test('does not create task when only tokens are provided', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', '#projeto !high @hoje')
        ->call('createTask');

    expect(Activity::count())->toBe(0);
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

    $task = Activity::first();
    expect($task->due_date)->toEqual(now()->addDay()->startOfDay());
});

test('!emergency creates the task as padrão and defers to the blocking emergency modal', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa urgente !emergency')
        ->call('createTask')
        ->assertDispatched('open-emergency-modal');

    $task = Activity::first();

    // Quick-Add has no field for the motivo (nor for resolving a clash with
    // the Emergência already on the board), so it hands both questions to
    // the modal rather than classifying blind.
    expect($task->service_class)->toBe(ServiceClass::Standard)
        ->and($task->emergency_reason)->toBeNull();
});

test('invalid service class token falls back to standard with a toast', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa qualquer !nao-existe')
        ->call('createTask');

    $task = Activity::first();
    expect($task->service_class)->toBe(ServiceClass::Standard);
});

test('quick add creates session task with > prefix', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', '>Implementar autenticação com OAuth')
        ->call('createTask');

    expect(Activity::count())->toBe(1);

    $task = Activity::first();
    expect($task->session_prompt)->toBe('Implementar autenticação com OAuth');
    expect($task->title)->toBe('Implementar autenticação com OAuth');
});

test('quick add creates session task via checkbox mode', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Refatorar módulo de pagamentos')
        ->set('isSessionMode', true)
        ->set('sessionPromptInput', 'Refatorar o módulo de pagamentos para usar Stripe SDK v3')
        ->call('createTask');

    expect(Activity::count())->toBe(1);

    $task = Activity::first();
    expect($task->session_prompt)->toBe('Refatorar o módulo de pagamentos para usar Stripe SDK v3');
    expect($task->title)->toBe('Refatorar módulo de pagamentos');
});

test('quick add resets session fields after creating task', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa normal')
        ->set('isSessionMode', true)
        ->set('sessionPromptInput', 'Some prompt')
        ->call('createTask')
        ->assertSet('isSessionMode', false)
        ->assertSet('sessionPromptInput', '');
});

test('quick add creates normal task without > prefix', function () {
    Livewire::test('task-quick-add')
        ->set('rawInput', 'Tarefa normal sem sessão')
        ->call('createTask');

    $task = Activity::first();
    expect($task->session_prompt)->toBeNull();
});

test('can open quick add with pre-selected status via event', function () {
    Livewire::test('task-quick-add')
        ->dispatch('open-quick-add-with-status', status: 'todo')
        ->assertSet('initialStatus', 'todo');
});

test('creates task with initial status when set', function () {
    Livewire::test('task-quick-add')
        ->set('initialStatus', 'todo')
        ->set('rawInput', 'Tarefa para Todo')
        ->call('createTask');

    $task = Activity::first();
    expect($task->status)->toBe(ActivityStatus::Todo);
});

test('creates task with backlog status when initial status is backlog', function () {
    Livewire::test('task-quick-add')
        ->set('initialStatus', 'backlog')
        ->set('rawInput', 'Tarefa para Backlog')
        ->call('createTask');

    $task = Activity::first();
    expect($task->status)->toBe(ActivityStatus::Backlog);
});

test('creates task with doing status when initial status is doing', function () {
    Livewire::test('task-quick-add')
        ->set('initialStatus', 'doing')
        ->set('rawInput', 'Tarefa em progresso')
        ->call('createTask');

    $task = Activity::first();
    expect($task->status)->toBe(ActivityStatus::Doing);
});

test('creates task with done status when initial status is done', function () {
    Livewire::test('task-quick-add')
        ->set('initialStatus', 'done')
        ->set('rawInput', 'Tarefa concluída')
        ->call('createTask');

    $task = Activity::first();
    expect($task->status)->toBe(ActivityStatus::Done);
});

test('falls back to inbox when initial status is invalid', function () {
    Livewire::test('task-quick-add')
        ->set('initialStatus', 'invalid-status')
        ->set('rawInput', 'Tarefa com status inválido')
        ->call('createTask');

    $task = Activity::first();
    expect($task->status)->toBe(ActivityStatus::Inbox);
});

test('resets initial status after creating task', function () {
    Livewire::test('task-quick-add')
        ->set('initialStatus', 'todo')
        ->set('rawInput', 'Tarefa para Todo')
        ->call('createTask')
        ->assertSet('initialStatus', null);
});

test('creates task in inbox when initial status is null', function () {
    Livewire::test('task-quick-add')
        ->set('initialStatus', null)
        ->set('rawInput', 'Tarefa normal')
        ->call('createTask');

    $task = Activity::first();
    expect($task->status)->toBe(ActivityStatus::Inbox);
});
