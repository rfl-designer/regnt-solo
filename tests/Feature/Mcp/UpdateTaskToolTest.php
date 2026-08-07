<?php

use App\Enums\ActivityStatus;
use App\Exceptions\WaitingRequiresWaitingForException;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('update-task updates title and status', function () {
    $task = Activity::factory()->task()->create(['title' => 'Old title', 'status' => ActivityStatus::Backlog]);

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'title' => 'New title',
        'status' => 'todo',
    ]);

    $response->assertOk();
    $response->assertSee('"title": "New title"');
    $response->assertSee('"status": "todo"');

    expect($task->fresh()->title)->toBe('New title')
        ->and($task->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('update-task marks done and stops running timer, same as UI', function () {
    $task = Activity::factory()->task()->create(['status' => ActivityStatus::Doing]);

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'status' => 'done',
    ]);

    $response->assertOk();
    expect($task->fresh()->status)->toBe(ActivityStatus::Done)
        ->and($task->fresh()->completed_at)->not->toBeNull();
});

test('update-task moving into a client-side wait auto-fills waiting_for from the effective client', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->task()->create(['project_id' => $project->id, 'status' => ActivityStatus::Todo]);

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'status' => 'awaiting_approval',
    ]);

    $response->assertOk();
    $response->assertSee('"waiting_for": "Acme Corp"');

    expect($task->fresh()->status)->toBe(ActivityStatus::AwaitingApproval)
        ->and($task->fresh()->waiting_since)->not->toBeNull();
});

test('update-task moving into the internal wait without waiting_for is refused with the domain message', function () {
    $task = Activity::factory()->task()->create(['status' => ActivityStatus::Doing]);

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'status' => 'waiting',
    ]);

    $response->assertHasErrors([WaitingRequiresWaitingForException::MESSAGE]);
    expect($task->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('update-task moving into the internal wait with waiting_for succeeds', function () {
    $task = Activity::factory()->task()->create(['status' => ActivityStatus::Doing]);

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'status' => 'waiting',
        'waiting_for' => 'Designer',
    ]);

    $response->assertOk();
    $response->assertSee('"waiting_for": "Designer"');
    expect($task->fresh()->status)->toBe(ActivityStatus::Waiting);
});

test('update-task leaving a waiting status clears waiting_for and waiting_since', function () {
    $task = Activity::factory()->task()->waiting('Designer')->create();

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'status' => 'doing',
    ]);

    $response->assertOk();
    expect($task->fresh()->waiting_for)->toBeNull()
        ->and($task->fresh()->waiting_since)->toBeNull();
});

test('update-task fails for an unknown task id', function () {
    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => 999999,
        'title' => 'Nope',
    ]);

    $response->assertHasErrors();
});

test('update-task only targets tasks, not issues or epics', function () {
    $issue = Activity::factory()->issue()->create();

    // Scoped to `tasks()`, so an issue id is simply not found — same
    // findOrFail-on-a-scoped-query pattern as UpdateIssueTool/UpdateEpicTool.
    expect(fn () => SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $issue->id,
        'title' => 'Should not apply',
    ]))->toThrow(ModelNotFoundException::class);

    expect($issue->fresh()->title)->not->toBe('Should not apply');
});
