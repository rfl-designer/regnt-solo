<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;

test('can create a task', function () {
    $task = Task::factory()->create([
        'title' => 'Minha tarefa',
    ]);

    expect($task)
        ->title->toBe('Minha tarefa')
        ->status->toBe(TaskStatus::Inbox)
        ->priority->toBe(TaskPriority::Medium);
});

test('task casts status and priority to enums', function () {
    $task = Task::factory()->create();

    expect($task->status)->toBeInstanceOf(TaskStatus::class)
        ->and($task->priority)->toBeInstanceOf(TaskPriority::class);
});

test('task belongs to a project', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    expect($task->project->id)->toBe($project->id);
});

test('task has many time entries', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->count(2)->create(['task_id' => $task->id]);

    expect($task->timeEntries)->toHaveCount(2);
});

test('task belongs to many daily plans', function () {
    $task = Task::factory()->create();
    $plan = DailyPlan::factory()->create();
    $plan->tasks()->attach($task, ['sort_order' => 1]);

    expect($task->dailyPlans)->toHaveCount(1);
});

test('inbox scope returns only inbox tasks', function () {
    Task::factory()->create();
    Task::factory()->todo()->create();

    $inbox = Task::query()->inbox()->get();

    expect($inbox)->toHaveCount(1)
        ->and($inbox->first()->status)->toBe(TaskStatus::Inbox);
});

test('active scope returns only non-done tasks', function () {
    Task::factory()->create();
    Task::factory()->todo()->create();
    Task::factory()->doing()->create();
    Task::factory()->done()->create();

    $active = Task::query()->active()->get();

    expect($active)->toHaveCount(3);
});

test('byStatus scope filters by specific status', function () {
    Task::factory()->create();
    Task::factory()->todo()->create();
    Task::factory()->todo()->create();
    Task::factory()->doing()->create();

    $todos = Task::query()->byStatus(TaskStatus::Todo)->get();

    expect($todos)->toHaveCount(2);
});

test('overdue scope returns tasks past due date that are not done', function () {
    Task::factory()->overdue()->create();
    Task::factory()->create(['due_date' => now()->addDays(5), 'status' => TaskStatus::Todo]);
    Task::factory()->done()->create(['due_date' => now()->subDays(3)]);

    $overdue = Task::query()->overdue()->get();

    expect($overdue)->toHaveCount(1);
});

test('unassigned scope returns tasks without a project', function () {
    Task::factory()->create();
    $project = Project::factory()->create();
    Task::factory()->create(['project_id' => $project->id]);

    $unassigned = Task::query()->unassigned()->get();

    expect($unassigned)->toHaveCount(1)
        ->and($unassigned->first()->project_id)->toBeNull();
});

test('doneThisWeek scope returns tasks completed this week', function () {
    Task::factory()->done()->create(['completed_at' => now()]);
    Task::factory()->done()->create(['completed_at' => now()->subWeeks(2)]);
    Task::factory()->create();

    $doneThisWeek = Task::query()->doneThisWeek()->get();

    expect($doneThisWeek)->toHaveCount(1);
});

test('isOverdue returns true when task is past due and not done', function () {
    $task = Task::factory()->overdue()->create();

    expect($task->isOverdue())->toBeTrue();
});

test('isOverdue returns false when task is done', function () {
    $task = Task::factory()->done()->create(['due_date' => now()->subDays(3)]);

    expect($task->isOverdue())->toBeFalse();
});

test('isOverdue returns false when task has no due date', function () {
    $task = Task::factory()->create();

    expect($task->isOverdue())->toBeFalse();
});

test('markAsDone sets status to done and completed_at', function () {
    $task = Task::factory()->doing()->create();

    $task->markAsDone();
    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at)->not->toBeNull();
});

test('markAsDone stops running time entries', function () {
    $task = Task::factory()->doing()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    $task->markAsDone();
    $entry->refresh();

    expect($entry->stopped_at)->not->toBeNull();
});
