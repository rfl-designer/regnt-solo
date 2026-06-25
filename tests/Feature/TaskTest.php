<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\TimeEntry;

test('can create a task', function () {
    $task = Activity::factory()->create([
        'title' => 'Minha tarefa',
    ]);

    expect($task)
        ->title->toBe('Minha tarefa')
        ->status->toBe(ActivityStatus::Inbox)
        ->priority->toBe(ActivityPriority::Medium);
});

test('task casts status and priority to enums', function () {
    $task = Activity::factory()->create();

    expect($task->status)->toBeInstanceOf(ActivityStatus::class)
        ->and($task->priority)->toBeInstanceOf(ActivityPriority::class);
});

test('task belongs to a project', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->create(['project_id' => $project->id]);

    expect($task->project->id)->toBe($project->id);
});

test('task has many time entries', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->count(2)->create(['activity_id' => $task->id]);

    expect($task->timeEntries)->toHaveCount(2);
});

test('task belongs to many daily plans', function () {
    $task = Activity::factory()->create();
    $plan = DailyPlan::factory()->create();
    $plan->tasks()->attach($task, ['sort_order' => 1]);

    expect($task->dailyPlans)->toHaveCount(1);
});

test('inbox scope returns only inbox tasks', function () {
    Activity::factory()->create();
    Activity::factory()->todo()->create();

    $inbox = Activity::query()->inbox()->get();

    expect($inbox)->toHaveCount(1)
        ->and($inbox->first()->status)->toBe(ActivityStatus::Inbox);
});

test('active scope returns only non-done tasks', function () {
    Activity::factory()->create();
    Activity::factory()->todo()->create();
    Activity::factory()->doing()->create();
    Activity::factory()->done()->create();

    $active = Activity::query()->active()->get();

    expect($active)->toHaveCount(3);
});

test('byStatus scope filters by specific status', function () {
    Activity::factory()->create();
    Activity::factory()->todo()->create();
    Activity::factory()->todo()->create();
    Activity::factory()->doing()->create();

    $todos = Activity::query()->byStatus(ActivityStatus::Todo)->get();

    expect($todos)->toHaveCount(2);
});

test('overdue scope returns tasks past due date that are not done', function () {
    Activity::factory()->overdue()->create();
    Activity::factory()->create(['due_date' => now()->addDays(5), 'status' => ActivityStatus::Todo]);
    Activity::factory()->done()->create(['due_date' => now()->subDays(3)]);

    $overdue = Activity::query()->overdue()->get();

    expect($overdue)->toHaveCount(1);
});

test('unassigned scope returns tasks without a project', function () {
    Activity::factory()->create();
    $project = Project::factory()->create();
    Activity::factory()->create(['project_id' => $project->id]);

    $unassigned = Activity::query()->unassigned()->get();

    expect($unassigned)->toHaveCount(1)
        ->and($unassigned->first()->project_id)->toBeNull();
});

test('doneThisWeek scope returns tasks completed this week', function () {
    Activity::factory()->done()->create(['completed_at' => now()]);
    Activity::factory()->done()->create(['completed_at' => now()->subWeeks(2)]);
    Activity::factory()->create();

    $doneThisWeek = Activity::query()->doneThisWeek()->get();

    expect($doneThisWeek)->toHaveCount(1);
});

test('isOverdue returns true when task is past due and not done', function () {
    $task = Activity::factory()->overdue()->create();

    expect($task->isOverdue())->toBeTrue();
});

test('isOverdue returns false when task is done', function () {
    $task = Activity::factory()->done()->create(['due_date' => now()->subDays(3)]);

    expect($task->isOverdue())->toBeFalse();
});

test('isOverdue returns false when task has no due date', function () {
    $task = Activity::factory()->create();

    expect($task->isOverdue())->toBeFalse();
});

test('markAsDone sets status to done and completed_at', function () {
    $task = Activity::factory()->doing()->create();

    $task->markAsDone();
    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Done)
        ->and($task->completed_at)->not->toBeNull();
});

test('markAsDone stops running time entries', function () {
    $task = Activity::factory()->doing()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    $task->markAsDone();
    $entry->refresh();

    expect($entry->stopped_at)->not->toBeNull();
});

test('startTimer creates a time entry and changes status to doing from inbox', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Inbox]);

    $entry = $task->startTimer();
    $task->refresh();

    expect($entry)->toBeInstanceOf(TimeEntry::class)
        ->and($entry->activity_id)->toBe($task->id)
        ->and($task->status)->toBe(ActivityStatus::Doing);
});

test('startTimer changes status to doing from backlog', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Backlog]);

    $task->startTimer();
    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Doing);
});

test('startTimer changes status to doing from todo', function () {
    $task = Activity::factory()->todo()->create();

    $task->startTimer();
    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Doing);
});

test('startTimer does not change status if already doing', function () {
    $task = Activity::factory()->doing()->create();

    $task->startTimer();
    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Doing);
});

test('startTimer does not change status if done', function () {
    $task = Activity::factory()->done()->create();

    $task->startTimer();
    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Done);
});

test('startTimer stops other running timers', function () {
    $task1 = Activity::factory()->doing()->create();
    $task2 = Activity::factory()->doing()->create();
    $runningEntry = TimeEntry::factory()->running()->create(['activity_id' => $task1->id]);

    $task2->startTimer();
    $runningEntry->refresh();

    expect($runningEntry->stopped_at)->not->toBeNull();
});

test('startTimer creates time entry with correct focus flag', function () {
    $task = Activity::factory()->doing()->create();

    $normalEntry = $task->startTimer(focus: false);
    expect($normalEntry->is_focus_session)->toBeFalse();

    $focusEntry = $task->startTimer(focus: true);
    expect($focusEntry->is_focus_session)->toBeTrue();
});

test('startTimer records status change in TaskStatusChange', function () {
    $task = Activity::factory()->todo()->create();
    $initialCount = $task->statusChanges()->count();

    $task->startTimer();

    expect($task->statusChanges()->count())->toBe($initialCount + 1);

    $lastChange = $task->statusChanges()->latest('id')->first();

    expect($lastChange->to_status)->toBe(ActivityStatus::Doing)
        ->and($lastChange->from_status)->toBe(ActivityStatus::Todo);
});
