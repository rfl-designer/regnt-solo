<?php

use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

test('can create a time entry', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->create(['task_id' => $task->id]);

    expect($entry->task->id)->toBe($task->id)
        ->and($entry->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($entry->stopped_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('duration_minutes calculates difference between started and stopped', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subMinutes(30),
        'stopped_at' => now(),
    ]);

    expect($entry->duration_minutes)->toBeGreaterThanOrEqual(29)
        ->and($entry->duration_minutes)->toBeLessThanOrEqual(31);
});

test('duration_minutes uses now when still running', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create([
        'task_id' => $task->id,
        'started_at' => now()->subMinutes(15),
    ]);

    expect($entry->duration_minutes)->toBeGreaterThanOrEqual(14)
        ->and($entry->duration_minutes)->toBeLessThanOrEqual(16);
});

test('running scope returns only entries without stopped_at', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->create(['task_id' => $task->id]);
    TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    $running = TimeEntry::query()->running()->get();

    expect($running)->toHaveCount(1)
        ->and($running->first()->stopped_at)->toBeNull();
});

test('forDate scope returns entries for a specific date', function () {
    $task = Task::factory()->create();
    $today = Carbon::today();

    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => $today->copy()->setHour(10),
        'stopped_at' => $today->copy()->setHour(11),
    ]);
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => $today->copy()->subDays(2)->setHour(10),
        'stopped_at' => $today->copy()->subDays(2)->setHour(11),
    ]);

    $entries = TimeEntry::query()->forDate($today)->get();

    expect($entries)->toHaveCount(1);
});

test('stopAllRunning stops all running entries', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->running()->count(3)->create(['task_id' => $task->id]);
    TimeEntry::factory()->create(['task_id' => $task->id]);

    $stopped = TimeEntry::stopAllRunning();

    expect($stopped)->toBe(3)
        ->and(TimeEntry::query()->running()->count())->toBe(0);
});

test('forWeek scope returns entries for current week', function () {
    $task = Task::factory()->create();
    $startOfWeek = Carbon::now()->startOfWeek();

    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => $startOfWeek->copy()->addDay()->setHour(10),
        'stopped_at' => $startOfWeek->copy()->addDay()->setHour(11),
    ]);
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => $startOfWeek->copy()->subWeek()->setHour(10),
        'stopped_at' => $startOfWeek->copy()->subWeek()->setHour(11),
    ]);

    $entries = TimeEntry::query()->forWeek()->get();

    expect($entries)->toHaveCount(1);
});
