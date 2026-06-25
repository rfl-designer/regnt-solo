<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use Carbon\CarbonImmutable;

test('creating a task records initial status change', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Todo]);

    $changes = ActivityStatusChange::where('activity_id', $task->id)->get();

    expect($changes)->toHaveCount(1)
        ->and($changes->first()->from_status)->toBeNull()
        ->and($changes->first()->to_status)->toBe(ActivityStatus::Todo)
        ->and($changes->first()->changed_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('updating status records a status change', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Inbox]);

    $task->update(['status' => ActivityStatus::Doing]);

    $changes = ActivityStatusChange::where('activity_id', $task->id)
        ->orderBy('changed_at')
        ->get();

    expect($changes)->toHaveCount(2)
        ->and($changes[0]->from_status)->toBeNull()
        ->and($changes[0]->to_status)->toBe(ActivityStatus::Inbox)
        ->and($changes[1]->from_status)->toBe(ActivityStatus::Inbox)
        ->and($changes[1]->to_status)->toBe(ActivityStatus::Doing);
});

test('updating a non-status field does not record a status change', function () {
    $task = Activity::factory()->create();

    $task->update(['title' => 'Updated title']);

    $changes = ActivityStatusChange::where('activity_id', $task->id)->get();

    expect($changes)->toHaveCount(1); // Only the initial creation change
});

test('timeInStatus calculates accumulated time per status correctly', function () {
    $task = Activity::withoutEvents(fn () => Activity::factory()->create(['status' => ActivityStatus::Doing]));

    $baseTime = now()->subMinutes(120);

    // Inbox for 30 minutes
    ActivityStatusChange::create([
        'activity_id' => $task->id,
        'from_status' => null,
        'to_status' => ActivityStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    // Backlog for 30 minutes
    ActivityStatusChange::create([
        'activity_id' => $task->id,
        'from_status' => ActivityStatus::Inbox,
        'to_status' => ActivityStatus::Backlog,
        'changed_at' => $baseTime->copy()->addMinutes(30),
    ]);

    // Doing for ~60 minutes (until now)
    ActivityStatusChange::create([
        'activity_id' => $task->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Doing,
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
    $task = Activity::withoutEvents(fn () => Activity::factory()->create(['status' => ActivityStatus::Doing]));

    ActivityStatusChange::create([
        'activity_id' => $task->id,
        'from_status' => ActivityStatus::Todo,
        'to_status' => ActivityStatus::Doing,
        'changed_at' => now()->subMinutes(45),
    ]);

    $duration = $task->current_status_duration;

    expect($duration)->toBeGreaterThanOrEqual(44)
        ->and($duration)->toBeLessThanOrEqual(46);
});

test('deleting a task cascades deletion to status changes', function () {
    $task = Activity::factory()->create();

    expect(ActivityStatusChange::where('activity_id', $task->id)->count())->toBe(1);

    $task->delete();

    expect(ActivityStatusChange::where('activity_id', $task->id)->count())->toBe(0);
});

test('forTask scope filters by task id', function () {
    $task1 = Activity::factory()->create();
    $task2 = Activity::factory()->create();

    $results = ActivityStatusChange::query()->forActivity($task1->id)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->activity_id)->toBe($task1->id);
});

test('forStatus scope filters by to_status', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Inbox]);
    $task->update(['status' => ActivityStatus::Doing]);

    $inboxChanges = ActivityStatusChange::query()->forStatus(ActivityStatus::Inbox)->get();
    $doingChanges = ActivityStatusChange::query()->forStatus(ActivityStatus::Doing)->get();

    expect($inboxChanges)->toHaveCount(1)
        ->and($doingChanges)->toHaveCount(1);
});
