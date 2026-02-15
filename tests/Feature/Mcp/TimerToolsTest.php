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
