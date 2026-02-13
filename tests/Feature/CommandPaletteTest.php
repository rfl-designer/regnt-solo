<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('component renders successfully', function () {
    Livewire::test('command-palette')
        ->assertSuccessful();
});

test('search returns matching tasks', function () {
    $project = Project::factory()->create(['name' => 'API Gateway']);
    Task::factory()->create(['title' => 'Implementar autenticação', 'project_id' => $project->id]);
    Task::factory()->create(['title' => 'Corrigir bug no login']);

    Livewire::test('command-palette')
        ->set('search', 'autenticação')
        ->assertSee('Implementar autenticação')
        ->assertDontSee('Corrigir bug no login');
});

test('search returns matching projects', function () {
    Project::factory()->create(['name' => 'API Gateway', 'slug' => 'api-gateway']);
    Project::factory()->create(['name' => 'Mobile App', 'slug' => 'mobile-app']);

    Livewire::test('command-palette')
        ->set('search', 'API')
        ->assertSee('API Gateway')
        ->assertDontSee('Mobile App');
});

test('empty search shows no results', function () {
    Task::factory()->create(['title' => 'Some task']);

    Livewire::test('command-palette')
        ->set('search', '')
        ->assertDontSee('Some task');
});

test('command prefix shows available commands', function () {
    Livewire::test('command-palette')
        ->set('search', '>')
        ->assertSee('mover')
        ->assertSee('timer')
        ->assertSee('deletar')
        ->assertSee('projeto')
        ->assertSee('prioridade')
        ->assertSee('planejar');
});

test('command mover changes task status', function () {
    $task = Task::factory()->todo()->create(['title' => 'Minha task']);

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':doing');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Doing);
});

test('command mover to done marks task as done', function () {
    $task = Task::factory()->todo()->create(['title' => 'Minha task']);

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':done');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Done);
    expect($task->completed_at)->not->toBeNull();
});

test('command timer starts timer for task', function () {
    $task = Task::factory()->doing()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'timer:'.$task->id);

    expect(TimeEntry::where('task_id', $task->id)->running()->count())->toBe(1);
});

test('command timer stops running timer', function () {
    $task = Task::factory()->doing()->create();
    TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'timer:'.$task->id);

    expect(TimeEntry::where('task_id', $task->id)->running()->count())->toBe(0);
});

test('command deletar removes task', function () {
    $task = Task::factory()->create(['title' => 'Task para deletar']);

    Livewire::test('command-palette')
        ->call('executeCommand', 'deletar:'.$task->id);

    expect(Task::find($task->id))->toBeNull();
});

test('command projeto assigns task to project', function () {
    $project = Project::factory()->create(['slug' => 'meu-projeto']);
    $task = Task::factory()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'projeto:'.$task->id.':'.$project->slug);

    $task->refresh();
    expect($task->project_id)->toBe($project->id);
});

test('command prioridade changes task priority', function () {
    $task = Task::factory()->create(['priority' => TaskPriority::Low]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'prioridade:'.$task->id.':urgent');

    $task->refresh();
    expect($task->priority)->toBe(TaskPriority::Urgent);
});

test('command planejar adds task to today plan', function () {
    $task = Task::factory()->todo()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'planejar:'.$task->id);

    $plan = DailyPlan::whereDate('date', now()->toDateString())->first();
    expect($plan)->not->toBeNull();
    expect($plan->tasks()->where('task_id', $task->id)->exists())->toBeTrue();
});

test('command planejar does not duplicate task in plan', function () {
    $task = Task::factory()->todo()->create();
    $plan = DailyPlan::create(['date' => now()->toDateString()]);
    $plan->tasks()->attach($task->id, ['sort_order' => 0]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'planejar:'.$task->id);

    expect($plan->tasks()->where('task_id', $task->id)->count())->toBe(1);
});

test('open task dispatches event and resets search', function () {
    $task = Task::factory()->create();

    Livewire::test('command-palette')
        ->set('search', 'something')
        ->call('openTask', $task->id)
        ->assertSet('search', '')
        ->assertDispatched('open-task-modal', taskId: $task->id);
});

test('open project redirects to project detail', function () {
    $project = Project::factory()->create(['slug' => 'test-project']);

    Livewire::test('command-palette')
        ->set('search', 'something')
        ->call('openProject', $project->slug)
        ->assertSet('search', '')
        ->assertRedirect(route('project.detail', 'test-project'));
});

test('invalid command shows warning', function () {
    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:0');
});

test('mover with invalid status shows warning', function () {
    $task = Task::factory()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':invalid');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Inbox);
});

test('projeto with invalid slug shows warning', function () {
    $task = Task::factory()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'projeto:'.$task->id.':nonexistent');

    $task->refresh();
    expect($task->project_id)->toBeNull();
});

test('prioridade with invalid value shows warning', function () {
    $task = Task::factory()->create(['priority' => TaskPriority::Medium]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'prioridade:'.$task->id.':invalid');

    $task->refresh();
    expect($task->priority)->toBe(TaskPriority::Medium);
});
