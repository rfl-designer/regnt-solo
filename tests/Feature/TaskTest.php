<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

test('can create a task with all fields', function () {
    $project = Project::factory()->create();

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'My Task',
        'description' => 'Task description',
        'status' => TaskStatus::Todo,
        'priority' => TaskPriority::High,
        'due_date' => '2026-03-01',
        'estimated_minutes' => 60,
        'sort_order' => 5,
    ]);

    expect($task)
        ->title->toBe('My Task')
        ->description->toBe('Task description')
        ->estimated_minutes->toBe(60)
        ->sort_order->toBe(5)
        ->project_id->toBe($project->id);

    $this->assertDatabaseHas('tasks', [
        'title' => 'My Task',
        'project_id' => $project->id,
    ]);
});

test('can create a task without a project', function () {
    $task = Task::factory()->create([
        'project_id' => null,
        'title' => 'Standalone Task',
    ]);

    expect($task)
        ->project_id->toBeNull()
        ->title->toBe('Standalone Task');

    $this->assertDatabaseHas('tasks', [
        'title' => 'Standalone Task',
        'project_id' => null,
    ]);
});

test('status is cast to TaskStatus enum', function () {
    $task = Task::factory()->inbox()->create();

    expect($task->status)
        ->toBeInstanceOf(TaskStatus::class)
        ->toBe(TaskStatus::Inbox);

    $task->update(['status' => TaskStatus::Doing]);
    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Doing);
});

test('priority is cast to TaskPriority enum', function () {
    $task = Task::factory()->create(['priority' => TaskPriority::Urgent]);

    expect($task->priority)
        ->toBeInstanceOf(TaskPriority::class)
        ->toBe(TaskPriority::Urgent);

    $task->update(['priority' => TaskPriority::Low]);
    $task->refresh();

    expect($task->priority)->toBe(TaskPriority::Low);
});

test('due_date is cast to date and completed_at to datetime', function () {
    $task = Task::factory()->done()->create([
        'due_date' => '2026-03-15',
    ]);

    expect($task->due_date)->toBeInstanceOf(CarbonImmutable::class);
    expect($task->completed_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('inbox scope returns only inbox tasks', function () {
    Task::factory()->inbox()->count(3)->create();
    Task::factory()->todo()->create();
    Task::factory()->doing()->create();

    $inboxTasks = Task::inbox()->get();

    expect($inboxTasks)->toHaveCount(3)
        ->each(fn ($task) => $task->status->toBe(TaskStatus::Inbox));
});

test('active scope returns only non-done tasks', function () {
    Task::factory()->inbox()->count(2)->create();
    Task::factory()->todo()->create();
    Task::factory()->doing()->create();
    Task::factory()->done()->count(3)->create();

    $activeTasks = Task::active()->get();

    expect($activeTasks)->toHaveCount(4)
        ->each(fn ($task) => $task->status->not->toBe(TaskStatus::Done));
});

test('byStatus scope filters by a specific status', function () {
    Task::factory()->inbox()->count(2)->create();
    Task::factory()->todo()->count(3)->create();
    Task::factory()->doing()->create();

    $todoTasks = Task::byStatus(TaskStatus::Todo)->get();

    expect($todoTasks)->toHaveCount(3)
        ->each(fn ($task) => $task->status->toBe(TaskStatus::Todo));
});

test('overdue scope returns tasks with past due_date that are not done', function () {
    Task::factory()->overdue()->count(2)->create();
    Task::factory()->done()->create(['due_date' => now()->subDays(3)]);
    Task::factory()->todo()->create(['due_date' => now()->addDays(5)]);
    Task::factory()->inbox()->create(['due_date' => null]);

    $overdueTasks = Task::overdue()->get();

    expect($overdueTasks)->toHaveCount(2);
});

test('unassigned scope returns tasks without a project', function () {
    Task::factory()->count(3)->create(['project_id' => null]);
    Task::factory()->withProject()->count(2)->create();

    $unassignedTasks = Task::unassigned()->get();

    expect($unassignedTasks)->toHaveCount(3)
        ->each(fn ($task) => $task->project_id->toBeNull());
});

test('doneThisWeek scope returns tasks completed this week', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-11 12:00:00')); // Wednesday

    Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => Carbon::parse('2026-02-09 10:00:00'), // Monday
    ]);
    Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => Carbon::parse('2026-02-11 08:00:00'), // Wednesday
    ]);
    // Last week
    Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => Carbon::parse('2026-02-01 10:00:00'),
    ]);

    $doneThisWeek = Task::doneThisWeek()->get();

    expect($doneThisWeek)->toHaveCount(2);

    Carbon::setTestNow();
});

test('isOverdue returns true when due_date is past and task is not done', function () {
    $overdueTask = Task::factory()->overdue()->create();

    expect($overdueTask->isOverdue())->toBeTrue();
});

test('isOverdue returns false when task is done', function () {
    $doneTask = Task::factory()->done()->create([
        'due_date' => now()->subDays(3),
    ]);

    expect($doneTask->isOverdue())->toBeFalse();
});

test('isOverdue returns false when due_date is in the future', function () {
    $futureTask = Task::factory()->todo()->create([
        'due_date' => now()->addDays(5),
    ]);

    expect($futureTask->isOverdue())->toBeFalse();
});

test('isOverdue returns false when due_date is null', function () {
    $task = Task::factory()->todo()->create(['due_date' => null]);

    expect($task->isOverdue())->toBeFalse();
});

test('markAsDone sets status to done and completed_at to now', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-13 15:00:00'));

    $task = Task::factory()->doing()->create();
    $task->markAsDone();
    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Done);
    expect($task->completed_at)->not->toBeNull();
    expect($task->completed_at->toDateTimeString())->toBe('2026-02-13 15:00:00');

    Carbon::setTestNow();
});

test('task belongs to a project', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    expect($task->project)->toBeInstanceOf(Project::class);
    expect($task->project->id)->toBe($project->id);
});

test('project_id is nullable and task without project is valid', function () {
    $task = Task::factory()->create(['project_id' => null]);

    expect($task->project)->toBeNull();
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => null]);
});

test('deleting a project sets task project_id to null', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $project->delete();
    $task->refresh();

    expect($task->project_id)->toBeNull();
});

test('task status enum has correct labels', function () {
    expect(TaskStatus::Inbox->label())->toBe('Caixa de Entrada');
    expect(TaskStatus::Backlog->label())->toBe('Backlog');
    expect(TaskStatus::Todo->label())->toBe('A Fazer');
    expect(TaskStatus::Doing->label())->toBe('Fazendo');
    expect(TaskStatus::Done->label())->toBe('Concluído');
});

test('task status enum has correct colors', function () {
    expect(TaskStatus::Inbox->color())->toBe('zinc');
    expect(TaskStatus::Backlog->color())->toBe('slate');
    expect(TaskStatus::Todo->color())->toBe('sky');
    expect(TaskStatus::Doing->color())->toBe('amber');
    expect(TaskStatus::Done->color())->toBe('green');
});

test('task status enum has correct icons', function () {
    expect(TaskStatus::Inbox->icon())->toBe('inbox');
    expect(TaskStatus::Backlog->icon())->toBe('queue-list');
    expect(TaskStatus::Todo->icon())->toBe('clipboard-document-list');
    expect(TaskStatus::Doing->icon())->toBe('play-circle');
    expect(TaskStatus::Done->icon())->toBe('check-circle');
});

test('task priority enum has correct labels', function () {
    expect(TaskPriority::Urgent->label())->toBe('Urgente');
    expect(TaskPriority::High->label())->toBe('Alta');
    expect(TaskPriority::Medium->label())->toBe('Média');
    expect(TaskPriority::Low->label())->toBe('Baixa');
});

test('task priority enum has correct colors', function () {
    expect(TaskPriority::Urgent->color())->toBe('rose');
    expect(TaskPriority::High->color())->toBe('red');
    expect(TaskPriority::Medium->color())->toBe('amber');
    expect(TaskPriority::Low->color())->toBe('sky');
});

test('task priority enum has correct icons', function () {
    expect(TaskPriority::Urgent->icon())->toBe('fire');
    expect(TaskPriority::High->icon())->toBe('arrow-up-circle');
    expect(TaskPriority::Medium->icon())->toBe('minus-circle');
    expect(TaskPriority::Low->icon())->toBe('arrow-down-circle');
});
