<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\Carbon;

test('can create a time entry with all fields', function () {
    $task = Task::factory()->withProject()->create();

    $entry = TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => '2026-02-13 09:00:00',
        'stopped_at' => '2026-02-13 10:30:00',
        'notes' => 'Working on feature',
    ]);

    expect($entry)
        ->task_id->toBe($task->id)
        ->notes->toBe('Working on feature');

    expect($entry->started_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class);
    expect($entry->stopped_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class);

    $this->assertDatabaseHas('time_entries', [
        'task_id' => $task->id,
        'notes' => 'Working on feature',
    ]);
});

test('duration_minutes accessor returns correct minutes for stopped entry', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-13 12:00:00'));

    $entry = TimeEntry::factory()->create([
        'started_at' => '2026-02-13 09:00:00',
        'stopped_at' => '2026-02-13 10:30:00',
    ]);

    expect($entry->duration_minutes)->toBe(90);

    Carbon::setTestNow();
});

test('duration_minutes accessor calculates from now when entry is running', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-13 10:00:00'));

    $entry = TimeEntry::factory()->running()->create([
        'started_at' => '2026-02-13 09:00:00',
    ]);

    expect($entry->duration_minutes)->toBe(60);

    Carbon::setTestNow();
});

test('running scope returns only entries without stopped_at', function () {
    TimeEntry::factory()->running()->count(2)->create();
    TimeEntry::factory()->stopped()->count(3)->create();

    $running = TimeEntry::running()->get();

    expect($running)->toHaveCount(2)
        ->each(fn ($entry) => $entry->stopped_at->toBeNull());
});

test('forDate scope returns entries for a specific date', function () {
    $task = Task::factory()->withProject()->create();

    TimeEntry::factory()->forTask($task)->create([
        'started_at' => '2026-02-13 09:00:00',
        'stopped_at' => '2026-02-13 10:00:00',
    ]);
    TimeEntry::factory()->forTask($task)->create([
        'started_at' => '2026-02-13 14:00:00',
        'stopped_at' => '2026-02-13 15:00:00',
    ]);
    TimeEntry::factory()->forTask($task)->create([
        'started_at' => '2026-02-12 09:00:00',
        'stopped_at' => '2026-02-12 10:00:00',
    ]);

    $entries = TimeEntry::forDate(Carbon::parse('2026-02-13'))->get();

    expect($entries)->toHaveCount(2);
});

test('forDate scope accepts a string date', function () {
    $task = Task::factory()->withProject()->create();

    TimeEntry::factory()->forTask($task)->create([
        'started_at' => '2026-02-13 09:00:00',
        'stopped_at' => '2026-02-13 10:00:00',
    ]);

    $entries = TimeEntry::forDate('2026-02-13')->get();

    expect($entries)->toHaveCount(1);
});

test('forWeek scope returns entries from current week', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-11 12:00:00')); // Wednesday

    $task = Task::factory()->withProject()->create();

    // Monday of this week
    TimeEntry::factory()->forTask($task)->create([
        'started_at' => '2026-02-09 09:00:00',
        'stopped_at' => '2026-02-09 10:00:00',
    ]);
    // Wednesday of this week
    TimeEntry::factory()->forTask($task)->create([
        'started_at' => '2026-02-11 09:00:00',
        'stopped_at' => '2026-02-11 10:00:00',
    ]);
    // Last week
    TimeEntry::factory()->forTask($task)->create([
        'started_at' => '2026-02-02 09:00:00',
        'stopped_at' => '2026-02-02 10:00:00',
    ]);

    $entries = TimeEntry::forWeek()->get();

    expect($entries)->toHaveCount(2);

    Carbon::setTestNow();
});

test('forProject scope returns entries for a specific project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    $taskA = Task::factory()->create(['project_id' => $projectA->id]);
    $taskB = Task::factory()->create(['project_id' => $projectB->id]);

    TimeEntry::factory()->forTask($taskA)->count(3)->create();
    TimeEntry::factory()->forTask($taskB)->count(2)->create();

    $entries = TimeEntry::forProject($projectA->id)->get();

    expect($entries)->toHaveCount(3);
});

test('stopAllRunning stops all running entries and returns count', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-13 15:00:00'));

    TimeEntry::factory()->running()->count(3)->create();
    TimeEntry::factory()->stopped()->count(2)->create();

    $stoppedCount = TimeEntry::stopAllRunning();

    expect($stoppedCount)->toBe(3);
    expect(TimeEntry::running()->count())->toBe(0);
    expect(TimeEntry::whereNotNull('stopped_at')->count())->toBe(5);

    Carbon::setTestNow();
});

test('stopAllRunning returns zero when no running entries', function () {
    TimeEntry::factory()->stopped()->count(2)->create();

    $stoppedCount = TimeEntry::stopAllRunning();

    expect($stoppedCount)->toBe(0);
});

test('deleting a task cascades to time entries', function () {
    $task = Task::factory()->withProject()->create();

    TimeEntry::factory()->forTask($task)->count(3)->create();

    expect(TimeEntry::where('task_id', $task->id)->count())->toBe(3);

    $task->delete();

    expect(TimeEntry::where('task_id', $task->id)->count())->toBe(0);
});

test('time entry belongs to a task', function () {
    $task = Task::factory()->withProject()->create();
    $entry = TimeEntry::factory()->forTask($task)->create();

    expect($entry->task)->toBeInstanceOf(Task::class);
    expect($entry->task->id)->toBe($task->id);
});

test('task isRunning returns true when it has a running time entry', function () {
    $task = Task::factory()->withProject()->create();

    TimeEntry::factory()->forTask($task)->running()->create();

    expect($task->isRunning())->toBeTrue();
});

test('task isRunning returns false when all time entries are stopped', function () {
    $task = Task::factory()->withProject()->create();

    TimeEntry::factory()->forTask($task)->stopped()->count(2)->create();

    expect($task->isRunning())->toBeFalse();
});

test('task isRunning returns false when task has no time entries', function () {
    $task = Task::factory()->withProject()->create();

    expect($task->isRunning())->toBeFalse();
});

test('notes field is nullable', function () {
    $entry = TimeEntry::factory()->create(['notes' => null]);

    expect($entry->notes)->toBeNull();
});

test('task has many time entries', function () {
    $task = Task::factory()->withProject()->create();

    TimeEntry::factory()->forTask($task)->count(3)->create();

    expect($task->timeEntries)->toHaveCount(3);
    expect($task->timeEntries->first())->toBeInstanceOf(TimeEntry::class);
});
