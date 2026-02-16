<?php

use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('daily route requires authentication', function () {
    auth()->logout();

    $this->get(route('daily'))
        ->assertRedirect(route('login'));
});

test('daily planner page renders correctly for authenticated users', function () {
    $this->get(route('daily'))
        ->assertOk();
});

test('daily planner component renders successfully', function () {
    Livewire::test('pages::daily-planner')
        ->assertSuccessful()
        ->assertSee('Daily Planner');
});

test('daily planner defaults to today', function () {
    Livewire::test('pages::daily-planner')
        ->assertSet('date', now()->toDateString());
});

test('daily planner shows tasks added to the plan', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Task::factory()->todo()->create(['title' => 'Minha task do dia']);
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->assertSee('Minha task do dia');
});

test('daily planner can add a task to the plan', function () {
    $task = Task::factory()->todo()->create(['title' => 'Task para adicionar']);

    Livewire::test('pages::daily-planner')
        ->call('addToPlan', $task->id);

    $plan = DailyPlan::whereDate('date', now()->toDateString())->first();

    expect($plan->tasks()->where('task_id', $task->id)->exists())->toBeTrue();
});

test('daily planner can remove a task from the plan', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Task::factory()->todo()->create(['title' => 'Task para remover']);
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->call('removeFromPlan', $task->id);

    expect($plan->fresh()->tasks()->where('task_id', $task->id)->exists())->toBeFalse();
});

test('daily planner can toggle task completion', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Task::factory()->todo()->create(['title' => 'Task para completar']);
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->call('toggleTask', $task->id);

    $pivot = $plan->fresh()->tasks()->where('task_id', $task->id)->first()->pivot;

    expect($pivot->completed_at)->not->toBeNull()
        ->and($task->fresh()->status)->toBe(TaskStatus::Done);
});

test('daily planner can undo task completion', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Task::factory()->done()->create(['title' => 'Task concluída']);
    $plan->tasks()->attach($task, ['sort_order' => 0, 'completed_at' => now()]);

    Livewire::test('pages::daily-planner')
        ->call('toggleTask', $task->id);

    $pivot = $plan->fresh()->tasks()->where('task_id', $task->id)->first()->pivot;

    expect($pivot->completed_at)->toBeNull()
        ->and($task->fresh()->status)->toBe(TaskStatus::Doing);
});

test('daily planner shows available tasks not in the plan', function () {
    $plan = DailyPlan::factory()->today()->create();
    $inPlan = Task::factory()->todo()->create(['title' => 'Task no plano']);
    $available = Task::factory()->todo()->create(['title' => 'Task disponível']);
    $plan->tasks()->attach($inPlan, ['sort_order' => 0]);

    $component = Livewire::test('pages::daily-planner');

    $availableTasks = $component->get('availableTasks');

    expect($availableTasks->pluck('id')->toArray())->toContain($available->id)
        ->and($availableTasks->pluck('id')->toArray())->not->toContain($inPlan->id);
});

test('daily planner does not show done tasks in available list', function () {
    $doneTask = Task::factory()->done()->create(['title' => 'Task concluída']);

    Livewire::test('pages::daily-planner')
        ->assertDontSee('Task concluída');
});

test('daily planner shows completion rate', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task1 = Task::factory()->done()->create();
    $task2 = Task::factory()->todo()->create();
    $plan->tasks()->attach($task1, ['sort_order' => 0, 'completed_at' => now()]);
    $plan->tasks()->attach($task2, ['sort_order' => 1]);

    Livewire::test('pages::daily-planner')
        ->assertSee('1/2 concluídas');
});

test('daily planner can change date via url parameter', function () {
    $date = Carbon::today()->addDay()->toDateString();

    Livewire::test('pages::daily-planner', ['date' => $date])
        ->assertSet('date', $date);
});

test('daily planner shows project info on tasks', function () {
    $project = Project::factory()->create(['name' => 'Projeto Alpha', 'emoji' => '🎯']);
    $plan = DailyPlan::factory()->today()->create();
    $task = Task::factory()->todo()->create(['project_id' => $project->id]);
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->assertSee('🎯')
        ->assertSee('Projeto Alpha');
});

test('daily planner shows priority badges', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Task::factory()->todo()->urgent()->create();
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->assertSee('Urgente');
});

test('daily planner can reorder tasks', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task1 = Task::factory()->todo()->create();
    $task2 = Task::factory()->todo()->create();
    $plan->tasks()->attach($task1, ['sort_order' => 0]);
    $plan->tasks()->attach($task2, ['sort_order' => 1]);

    Livewire::test('pages::daily-planner')
        ->call('handleSort', $task2->id, 0);

    expect($plan->tasks()->where('task_id', $task2->id)->first()->pivot->sort_order)->toBe(0);
});

test('daily planner saves notes on blur', function () {
    Livewire::test('pages::daily-planner')
        ->updateProperty('notes', 'Minhas notas do dia');

    $plan = DailyPlan::whereDate('date', now()->toDateString())->first();

    expect($plan->notes)->toBe('Minhas notas do dia');
});

test('daily planner prevents modifications on past plans', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $plan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Task::factory()->todo()->create();

    Livewire::test('pages::daily-planner', ['date' => $yesterday])
        ->call('addToPlan', $task->id);

    expect($plan->fresh()->tasks()->where('task_id', $task->id)->exists())->toBeFalse();
});

test('daily planner prevents toggle on past plans', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $plan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Task::factory()->todo()->create();
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner', ['date' => $yesterday])
        ->call('toggleTask', $task->id);

    expect($plan->fresh()->tasks()->where('task_id', $task->id)->first()->pivot->completed_at)->toBeNull();
});

test('daily planner prevents notes update on past plans', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $plan = DailyPlan::factory()->create(['date' => $yesterday, 'notes' => 'Original']);

    Livewire::test('pages::daily-planner', ['date' => $yesterday])
        ->updateProperty('notes', 'Tentativa de alteração');

    expect($plan->fresh()->notes)->toBe('Original');
});

test('daily planner shows carry-over banner when yesterday has incomplete tasks', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Task::factory()->todo()->create(['title' => 'Task de ontem']);
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->assertSee('1 task incompleta');
});

test('daily planner can carry over tasks from yesterday', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Task::factory()->todo()->create(['title' => 'Task de ontem']);
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->call('carryOver');

    $todayPlan = DailyPlan::whereDate('date', now()->toDateString())->first();

    expect($todayPlan->tasks()->where('task_id', $task->id)->exists())->toBeTrue();
});

test('daily planner can move past incomplete task to today', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Task::factory()->todo()->create();
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner', ['date' => $yesterday])
        ->call('moveToToday', $task->id);

    $todayPlan = DailyPlan::whereDate('date', now()->toDateString())->first();

    expect($todayPlan->tasks()->where('task_id', $task->id)->exists())->toBeTrue();
});

test('daily planner listens to task-updated event', function () {
    Livewire::test('pages::daily-planner')
        ->dispatch('task-updated')
        ->assertSuccessful();
});

test('daily planner listens to task-created event', function () {
    Livewire::test('pages::daily-planner')
        ->dispatch('task-created')
        ->assertSuccessful();
});

test('daily planner shows past plan badge', function () {
    $yesterday = Carbon::yesterday()->toDateString();

    Livewire::test('pages::daily-planner', ['date' => $yesterday])
        ->assertSee('Plano passado (somente leitura)');
});

test('daily planner hides available tasks column for past plans', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $task = Task::factory()->todo()->create(['title' => 'Task ativa']);

    Livewire::test('pages::daily-planner', ['date' => $yesterday])
        ->assertDontSee('Tasks Disponíveis');
});

test('daily planner prevents reorder on past plans', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $plan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task1 = Task::factory()->todo()->create();
    $task2 = Task::factory()->todo()->create();
    $plan->tasks()->attach($task1, ['sort_order' => 0]);
    $plan->tasks()->attach($task2, ['sort_order' => 1]);

    Livewire::test('pages::daily-planner', ['date' => $yesterday])
        ->call('handleSort', $task2->id, 0);

    expect($plan->fresh()->tasks()->where('task_id', $task2->id)->first()->pivot->sort_order)->toBe(1);
});

test('daily planner prevents remove on past plans', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $plan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Task::factory()->todo()->create();
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner', ['date' => $yesterday])
        ->call('removeFromPlan', $task->id);

    expect($plan->fresh()->tasks()->where('task_id', $task->id)->exists())->toBeTrue();
});

test('daily planner carry-over does nothing on non-today date', function () {
    $tomorrow = Carbon::tomorrow()->toDateString();
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Task::factory()->todo()->create();
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner', ['date' => $tomorrow])
        ->call('carryOver');

    $tomorrowPlan = DailyPlan::whereDate('date', $tomorrow)->first();

    expect($tomorrowPlan?->tasks()->where('task_id', $task->id)->exists() ?? false)->toBeFalse();
});

test('daily planner addToPlan does not duplicate existing task', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Task::factory()->todo()->create();
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->call('addToPlan', $task->id);

    expect($plan->fresh()->tasks()->where('task_id', $task->id)->count())->toBe(1);
});

test('daily planner toggleTask ignores task not in plan', function () {
    $task = Task::factory()->todo()->create();

    Livewire::test('pages::daily-planner')
        ->call('toggleTask', $task->id)
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Todo);
});

test('daily planner moveToToday ignores task not in current plan', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Task::factory()->todo()->create();

    Livewire::test('pages::daily-planner', ['date' => $yesterday])
        ->call('moveToToday', $task->id);

    $todayPlan = DailyPlan::whereDate('date', now()->toDateString())->first();

    expect($todayPlan)->toBeNull();
});

test('daily planner handles invalid date gracefully', function () {
    Livewire::test('pages::daily-planner', ['date' => 'not-a-date'])
        ->assertSet('date', now()->toDateString())
        ->assertSuccessful();
});

test('daily planner can filter available tasks by project', function () {
    $project1 = Project::factory()->create(['name' => 'Projeto Alpha']);
    $project2 = Project::factory()->create(['name' => 'Projeto Beta']);
    $task1 = Task::factory()->todo()->create(['project_id' => $project1->id, 'title' => 'Task Alpha']);
    $task2 = Task::factory()->todo()->create(['project_id' => $project2->id, 'title' => 'Task Beta']);

    $component = Livewire::test('pages::daily-planner');

    $availableTasks = $component->get('availableTasks');
    expect($availableTasks->pluck('id')->toArray())->toContain($task1->id)
        ->and($availableTasks->pluck('id')->toArray())->toContain($task2->id);

    $component->set('filterProjectId', $project1->id);

    $filteredTasks = $component->get('availableTasks');
    expect($filteredTasks->pluck('id')->toArray())->toContain($task1->id)
        ->and($filteredTasks->pluck('id')->toArray())->not->toContain($task2->id);
});

test('daily planner shows projects in filter dropdown', function () {
    $project = Project::factory()->create(['name' => 'Meu Projeto', 'emoji' => '🚀']);

    Livewire::test('pages::daily-planner')
        ->assertSee('🚀')
        ->assertSee('Meu Projeto');
});

test('daily planner filter resets when clearing project selection', function () {
    $project = Project::factory()->create();
    $task1 = Task::factory()->todo()->create(['project_id' => $project->id]);
    $task2 = Task::factory()->todo()->create(['project_id' => null]);

    $component = Livewire::test('pages::daily-planner')
        ->set('filterProjectId', $project->id);

    expect($component->get('availableTasks')->pluck('id')->toArray())->not->toContain($task2->id);

    $component->set('filterProjectId', null);

    expect($component->get('availableTasks')->pluck('id')->toArray())->toContain($task1->id)
        ->and($component->get('availableTasks')->pluck('id')->toArray())->toContain($task2->id);
});
