<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('kanban route requires authentication', function () {
    auth()->logout();

    $this->get(route('kanban'))
        ->assertRedirect(route('login'));
});

test('kanban page renders correctly for authenticated users', function () {
    $this->get(route('kanban'))
        ->assertOk();
});

test('kanban component renders successfully', function () {
    Livewire::test('pages::kanban')
        ->assertSuccessful()
        ->assertSee('Kanban');
});

test('kanban shows tasks in their respective columns', function () {
    $backlogTask = Task::factory()->backlog()->create(['title' => 'Backlog task']);
    $todoTask = Task::factory()->todo()->create(['title' => 'Todo task']);
    $doingTask = Task::factory()->doing()->create(['title' => 'Doing task']);
    $doneTask = Task::factory()->done()->create(['title' => 'Done task']);

    Livewire::test('pages::kanban')
        ->assertSee('Backlog task')
        ->assertSee('Todo task')
        ->assertSee('Doing task')
        ->assertSee('Done task');
});

test('kanban does not show inbox tasks', function () {
    Task::factory()->create(['title' => 'Inbox task']);

    Livewire::test('pages::kanban')
        ->assertDontSee('Inbox task');
});

test('kanban done column only shows tasks completed this week', function () {
    $thisWeekTask = Task::factory()->done()->create([
        'title' => 'Done this week',
        'completed_at' => Carbon::now(),
    ]);

    $lastWeekTask = Task::factory()->done()->create([
        'title' => 'Done last week',
        'completed_at' => Carbon::now()->subWeek(),
    ]);

    Livewire::test('pages::kanban')
        ->assertSee('Done this week')
        ->assertDontSee('Done last week');
});

test('kanban filters by project', function () {
    $project = Project::factory()->create();
    $projectTask = Task::factory()->backlog()->create([
        'title' => 'Project task',
        'project_id' => $project->id,
    ]);
    $otherTask = Task::factory()->backlog()->create(['title' => 'Other task']);

    Livewire::test('pages::kanban')
        ->set('filterProject', (string) $project->id)
        ->assertSee('Project task')
        ->assertDontSee('Other task');
});

test('kanban filters by priority', function () {
    Task::factory()->backlog()->urgent()->create(['title' => 'Urgent task']);
    Task::factory()->backlog()->create([
        'title' => 'Low task',
        'priority' => TaskPriority::Low,
    ]);

    Livewire::test('pages::kanban')
        ->set('filterPriority', TaskPriority::Urgent->value)
        ->assertSee('Urgent task')
        ->assertDontSee('Low task');
});

test('kanban filters overdue tasks', function () {
    Task::factory()->overdue()->create(['title' => 'Overdue task']);
    Task::factory()->todo()->create(['title' => 'Normal task']);

    Livewire::test('pages::kanban')
        ->set('filterOverdue', true)
        ->assertSee('Overdue task')
        ->assertDontSee('Normal task');
});

test('kanban overdue filter button shows active state when enabled', function () {
    Livewire::test('pages::kanban')
        ->assertSet('filterOverdue', false)
        ->set('filterOverdue', true)
        ->assertSet('filterOverdue', true);
});

test('kanban load more increases limit', function () {
    Livewire::test('pages::kanban')
        ->assertSet('limits.backlog', 20)
        ->call('loadMore', 'backlog')
        ->assertSet('limits.backlog', 40);
});

test('kanban handleSort moves task to new status', function () {
    $task = Task::factory()->backlog()->create(['title' => 'Move me']);

    Livewire::test('pages::kanban')
        ->call('handleSort', $task->id, 0, TaskStatus::Todo->value);

    expect($task->fresh()->status)->toBe(TaskStatus::Todo);
});

test('kanban handleSort to done marks task as done and syncs daily plan', function () {
    $task = Task::factory()->doing()->create(['title' => 'Complete me']);

    Livewire::test('pages::kanban')
        ->call('handleSort', $task->id, 0, TaskStatus::Done->value);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at)->not->toBeNull();

    $dailyPlan = DailyPlan::whereDate('date', Carbon::today())->first();

    expect($dailyPlan)->not->toBeNull()
        ->and($dailyPlan->tasks()->where('task_id', $task->id)->exists())->toBeTrue();
});

test('kanban handleSort to done stops running timers', function () {
    $task = Task::factory()->doing()->create();
    $timeEntry = TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subHour(),
        'stopped_at' => null,
    ]);

    Livewire::test('pages::kanban')
        ->call('handleSort', $task->id, 0, TaskStatus::Done->value);

    expect($timeEntry->fresh()->stopped_at)->not->toBeNull();
});

test('kanban shows project info on task cards', function () {
    $project = Project::factory()->create(['name' => 'Meu Projeto', 'emoji' => '🚀']);
    Task::factory()->backlog()->create(['project_id' => $project->id]);

    Livewire::test('pages::kanban')
        ->assertSee('🚀')
        ->assertSee('Meu Projeto');
});

test('kanban shows priority badges', function () {
    Task::factory()->backlog()->urgent()->create();

    Livewire::test('pages::kanban')
        ->assertSee('Urgente');
});

test('kanban shows estimate badges', function () {
    Task::factory()->backlog()->withEstimate(90)->create();

    Livewire::test('pages::kanban')
        ->assertSee('90m');
});

test('kanban shows overdue badge for overdue tasks', function () {
    $task = Task::factory()->overdue()->create();

    Livewire::test('pages::kanban')
        ->assertSee($task->due_date->diffForHumans());
});

test('kanban shows unassigned tasks section', function () {
    Task::factory()->backlog()->create([
        'title' => 'Unassigned task',
        'project_id' => null,
    ]);

    Livewire::test('pages::kanban')
        ->assertSee('Sem projeto')
        ->assertSee('Unassigned task');
});

test('kanban listens to task-updated event', function () {
    Livewire::test('pages::kanban')
        ->dispatch('task-updated')
        ->assertSuccessful();
});

test('kanban listens to task-created event', function () {
    Livewire::test('pages::kanban')
        ->dispatch('task-created')
        ->assertSuccessful();
});

test('kanban shows all four column headers', function () {
    Livewire::test('pages::kanban')
        ->assertSee('Backlog')
        ->assertSee('A Fazer')
        ->assertSee('Fazendo')
        ->assertSee('Concluída');
});
