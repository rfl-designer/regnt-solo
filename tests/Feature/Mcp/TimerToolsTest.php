<?php

use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\StartTimerTool;
use App\Mcp\Tools\StopTimerTool;
use App\Mcp\Tools\TimerStatusTool;
use App\Models\Task;
use App\Models\TimeEntry;

// StartTimerTool tests
test('start-timer creates a new time entry', function () {
    $task = Task::factory()->create();

    $response = SoloBoardServer::tool(StartTimerTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee($task->title);
    $response->assertSee('Timer started');

    $this->assertDatabaseHas('time_entries', [
        'task_id' => $task->id,
        'stopped_at' => null,
    ]);
});

test('start-timer stops existing running timer', function () {
    $task1 = Task::factory()->create();
    $task2 = Task::factory()->create();
    $existingEntry = TimeEntry::factory()->running()->create(['task_id' => $task1->id]);

    $response = SoloBoardServer::tool(StartTimerTool::class, [
        'task_id' => $task2->id,
    ]);

    $response->assertOk();

    $existingEntry->refresh();
    expect($existingEntry->stopped_at)->not->toBeNull();

    $this->assertDatabaseHas('time_entries', [
        'task_id' => $task2->id,
        'stopped_at' => null,
    ]);
});

test('start-timer fails for non-existent task', function () {
    $response = SoloBoardServer::tool(StartTimerTool::class, [
        'task_id' => 999,
    ]);

    $response->assertHasErrors();
});

test('start-timer changes task status to doing', function () {
    $task = Task::factory()->todo()->create();

    SoloBoardServer::tool(StartTimerTool::class, [
        'task_id' => $task->id,
    ]);

    $task->refresh();
    expect($task->status)->toBe(\App\Enums\TaskStatus::Doing);
});

test('start-timer does not change status if already doing or done', function () {
    $doingTask = Task::factory()->doing()->create();
    $doneTask = Task::factory()->done()->create();

    SoloBoardServer::tool(StartTimerTool::class, ['task_id' => $doingTask->id]);
    SoloBoardServer::tool(StartTimerTool::class, ['task_id' => $doneTask->id]);

    $doingTask->refresh();
    $doneTask->refresh();

    expect($doingTask->status)->toBe(\App\Enums\TaskStatus::Doing)
        ->and($doneTask->status)->toBe(\App\Enums\TaskStatus::Done);
});

// StopTimerTool tests
test('stop-timer stops running timer with notes', function () {
    $task = Task::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    $response = SoloBoardServer::tool(StopTimerTool::class, [
        'task_id' => $task->id,
        'notes' => 'Implemented authentication endpoint',
    ]);

    $response->assertOk();
    $response->assertSee('Timer stopped');
    $response->assertSee('Implemented authentication endpoint');

    $entry->refresh();
    expect($entry->stopped_at)->not->toBeNull();
    expect($entry->notes)->toBe('Implemented authentication endpoint');
});

test('stop-timer returns error when no running timer', function () {
    $task = Task::factory()->create();

    $response = SoloBoardServer::tool(StopTimerTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertHasErrors();
});

// TimerStatusTool tests
test('timer-status shows running timer', function () {
    $task = Task::factory()->create();
    TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    $response = SoloBoardServer::tool(TimerStatusTool::class, []);

    $response->assertOk();
    $response->assertSee('"running": true');
    $response->assertSee($task->title);
});

test('timer-status shows no timer message', function () {
    $response = SoloBoardServer::tool(TimerStatusTool::class, []);

    $response->assertOk();
    $response->assertSee('"running": false');
    $response->assertSee('No timer is currently running');
});

// Feature Timer Tests
test('start-timer creates entry for feature', function () {
    $feature = \App\Models\Feature::factory()->create();

    $response = SoloBoardServer::tool(StartTimerTool::class, [
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();
    $response->assertSee($feature->title);
    $response->assertSee('Timer started for feature');

    $this->assertDatabaseHas('time_entries', [
        'feature_id' => $feature->id,
        'task_id' => null,
        'stopped_at' => null,
    ]);
});

test('start-timer with feature_id stops existing running timers', function () {
    $task = Task::factory()->create();
    $feature = \App\Models\Feature::factory()->create();
    $existingEntry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    $response = SoloBoardServer::tool(StartTimerTool::class, [
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();

    $existingEntry->refresh();
    expect($existingEntry->stopped_at)->not->toBeNull();
});

test('start-timer fails without task_id or feature_id', function () {
    $response = SoloBoardServer::tool(StartTimerTool::class, []);

    $response->assertHasErrors();
});

test('stop-timer stops running feature timer with notes', function () {
    $feature = \App\Models\Feature::factory()->create();
    $entry = TimeEntry::factory()->running()->create(['feature_id' => $feature->id, 'task_id' => null]);

    $response = SoloBoardServer::tool(StopTimerTool::class, [
        'feature_id' => $feature->id,
        'notes' => 'Feature work completed',
    ]);

    $response->assertOk();
    $response->assertSee('Timer stopped for feature');
    $response->assertSee('Feature work completed');

    $entry->refresh();
    expect($entry->stopped_at)->not->toBeNull();
    expect($entry->notes)->toBe('Feature work completed');
});

test('stop-timer returns error when no running feature timer', function () {
    $feature = \App\Models\Feature::factory()->create();

    $response = SoloBoardServer::tool(StopTimerTool::class, [
        'feature_id' => $feature->id,
    ]);

    $response->assertHasErrors();
});

test('stop-timer fails without task_id or feature_id', function () {
    $response = SoloBoardServer::tool(StopTimerTool::class, []);

    $response->assertHasErrors();
});

test('timer-status shows running feature timer', function () {
    $feature = \App\Models\Feature::factory()->create();
    TimeEntry::factory()->running()->create(['feature_id' => $feature->id, 'task_id' => null]);

    $response = SoloBoardServer::tool(TimerStatusTool::class, []);

    $response->assertOk();
    $response->assertSee('"running": true');
    $response->assertSee('"feature_id":');
    $response->assertSee($feature->title);
});
