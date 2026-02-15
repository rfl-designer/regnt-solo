<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskStatusChange;
use Carbon\CarbonImmutable;

test('creating a task records initial status change', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Todo]);

    $changes = TaskStatusChange::where('task_id', $task->id)->get();

    expect($changes)->toHaveCount(1)
        ->and($changes->first()->from_status)->toBeNull()
        ->and($changes->first()->to_status)->toBe(TaskStatus::Todo)
        ->and($changes->first()->changed_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('updating status records a status change', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Inbox]);

    $task->update(['status' => TaskStatus::Doing]);

    $changes = TaskStatusChange::where('task_id', $task->id)
        ->orderBy('changed_at')
        ->get();

    expect($changes)->toHaveCount(2)
        ->and($changes[0]->from_status)->toBeNull()
        ->and($changes[0]->to_status)->toBe(TaskStatus::Inbox)
        ->and($changes[1]->from_status)->toBe(TaskStatus::Inbox)
        ->and($changes[1]->to_status)->toBe(TaskStatus::Doing);
});

test('updating a non-status field does not record a status change', function () {
    $task = Task::factory()->create();

    $task->update(['title' => 'Updated title']);

    $changes = TaskStatusChange::where('task_id', $task->id)->get();

    expect($changes)->toHaveCount(1); // Only the initial creation change
});

test('timeInStatus calculates accumulated time per status correctly', function () {
    $task = Task::withoutEvents(fn () => Task::factory()->create(['status' => TaskStatus::Doing]));

    $baseTime = now()->subMinutes(120);

    // Inbox for 30 minutes
    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => null,
        'to_status' => TaskStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    // Backlog for 30 minutes
    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Inbox,
        'to_status' => TaskStatus::Backlog,
        'changed_at' => $baseTime->copy()->addMinutes(30),
    ]);

    // Doing for ~60 minutes (until now)
    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Backlog,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $baseTime->copy()->addMinutes(60),
    ]);

    $timeInStatus = $task->time_in_status;

    expect($timeInStatus['inbox'])->toBeGreaterThanOrEqual(29)
        ->and($timeInStatus['inbox'])->toBeLessThanOrEqual(31)
        ->and($timeInStatus['backlog'])->toBeGreaterThanOrEqual(29)
        ->and($timeInStatus['backlog'])->toBeLessThanOrEqual(31)
        ->and($timeInStatus['doing'])->toBeGreaterThanOrEqual(59)
        ->and($timeInStatus['doing'])->toBeLessThanOrEqual(61)
        ->and($timeInStatus['todo'])->toBe(0.0)
        ->and($timeInStatus['done'])->toBe(0.0);
});

test('currentStatusDuration returns duration in current status', function () {
    $task = Task::withoutEvents(fn () => Task::factory()->create(['status' => TaskStatus::Doing]));

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Todo,
        'to_status' => TaskStatus::Doing,
        'changed_at' => now()->subMinutes(45),
    ]);

    $duration = $task->current_status_duration;

    expect($duration)->toBeGreaterThanOrEqual(44)
        ->and($duration)->toBeLessThanOrEqual(46);
});

test('deleting a task cascades deletion to status changes', function () {
    $task = Task::factory()->create();

    expect(TaskStatusChange::where('task_id', $task->id)->count())->toBe(1);

    $task->delete();

    expect(TaskStatusChange::where('task_id', $task->id)->count())->toBe(0);
});

test('forTask scope filters by task id', function () {
    $task1 = Task::factory()->create();
    $task2 = Task::factory()->create();

    $results = TaskStatusChange::query()->forTask($task1->id)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->task_id)->toBe($task1->id);
});

test('forStatus scope filters by to_status', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Inbox]);
    $task->update(['status' => TaskStatus::Doing]);

    $inboxChanges = TaskStatusChange::query()->forStatus(TaskStatus::Inbox)->get();
    $doingChanges = TaskStatusChange::query()->forStatus(TaskStatus::Doing)->get();

    expect($inboxChanges)->toHaveCount(1)
        ->and($doingChanges)->toHaveCount(1);
});
