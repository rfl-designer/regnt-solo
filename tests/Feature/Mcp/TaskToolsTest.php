<?php

use App\Enums\ActivityStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\DeleteTaskTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Activity;
use App\Models\Project;
use App\Models\TimeEntry;

// ListTasksTool tests
test('list-tasks returns all tasks', function () {
    Activity::factory()->count(3)->create();

    $response = SoloBoardServer::tool(ListTasksTool::class, []);

    $response->assertOk();
});

test('list-tasks filters by status', function () {
    Activity::factory()->create(['status' => ActivityStatus::Todo]);
    Activity::factory()->create(['status' => ActivityStatus::Done]);

    $response = SoloBoardServer::tool(ListTasksTool::class, [
        'status' => 'todo',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "todo"');
    $response->assertDontSee('"status": "done"');
});

test('list-tasks filters by project slug', function () {
    $project = Project::factory()->create(['slug' => 'my-project']);
    Activity::factory()->create(['project_id' => $project->id]);
    Activity::factory()->create(['project_id' => null]);

    $response = SoloBoardServer::tool(ListTasksTool::class, [
        'project_slug' => 'my-project',
    ]);

    $response->assertOk();
    $response->assertSee($project->name);
});

test('list-tasks respects limit', function () {
    Activity::factory()->count(5)->create();

    $response = SoloBoardServer::tool(ListTasksTool::class, [
        'limit' => 2,
    ]);

    $response->assertOk();
});

// GetTaskTool tests
test('get-task returns task with relationships', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->create(['project_id' => $project->id]);
    TimeEntry::factory()->create(['activity_id' => $task->id]);

    $response = SoloBoardServer::tool(GetTaskTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee($task->title);
    $response->assertSee($project->name);
});

test('get-task fails for non-existent task', function () {
    $response = SoloBoardServer::tool(GetTaskTool::class, [
        'task_id' => 999,
    ]);

    $response->assertHasErrors();
});

// CreateTaskTool tests
test('create-task creates task with defaults', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'New MCP Task',
    ]);

    $response->assertOk();
    $response->assertSee('New MCP Task');
    $response->assertSee('"status": "inbox"');
    $response->assertSee('"priority": "medium"');

    $this->assertDatabaseHas('activities', [
        'title' => 'New MCP Task',
        'status' => 'inbox',
        'priority' => 'medium',
    ]);
});

test('create-task creates task with project', function () {
    $project = Project::factory()->create(['slug' => 'test-project']);

    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Project Task',
        'project_slug' => 'test-project',
        'status' => 'todo',
        'priority' => 'high',
    ]);

    $response->assertOk();
    $response->assertSee('Project Task');

    $this->assertDatabaseHas('activities', [
        'title' => 'Project Task',
        'project_id' => $project->id,
        'status' => 'todo',
        'priority' => 'high',
    ]);
});

test('create-task fails without title', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, []);

    $response->assertHasErrors();
});

// UpdateTaskTool tests
test('update-task updates task fields', function () {
    $task = Activity::factory()->create(['title' => 'Old Title']);

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'title' => 'New Title',
        'priority' => 'urgent',
    ]);

    $response->assertOk();
    $response->assertSee('New Title');

    $this->assertDatabaseHas('activities', [
        'id' => $task->id,
        'title' => 'New Title',
        'priority' => 'urgent',
    ]);
});

test('update-task calls markAsDone when status changes to done', function () {
    $task = Activity::factory()->doing()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'status' => 'done',
    ]);

    $response->assertOk();

    $task->refresh();
    $entry->refresh();

    expect($task->status)->toBe(ActivityStatus::Done);
    expect($task->completed_at)->not->toBeNull();
    expect($entry->stopped_at)->not->toBeNull();
});

test('update-task fails for non-existent task', function () {
    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => 999,
        'title' => 'New Title',
    ]);

    $response->assertHasErrors();
});

// DeleteTaskTool tests
test('delete-task deletes task and time entries', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->count(2)->create(['activity_id' => $task->id]);

    $response = SoloBoardServer::tool(DeleteTaskTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('"deleted": true');

    $this->assertDatabaseMissing('activities', ['id' => $task->id]);
    $this->assertDatabaseCount('time_entries', 0);
});

test('delete-task fails for non-existent task', function () {
    $response = SoloBoardServer::tool(DeleteTaskTool::class, [
        'task_id' => 999,
    ]);

    $response->assertHasErrors();
});

// Reconciliation tests (issue #87)
test('reconciliation deletes the orphan mirrored task and preserves eligible ones', function () {
    $eligible = Activity::factory()->create(['github_issue_number' => 300]);
    TimeEntry::factory()->count(2)->create(['activity_id' => $eligible->id]);

    $orphan = Activity::factory()->create(['github_issue_number' => 301]);
    TimeEntry::factory()->count(3)->create(['activity_id' => $orphan->id]);

    // The orphan's issue is no longer eligible (e.g. became wontfix or was removed),
    // so the sync deletes its mirrored task via delete-task.
    $response = SoloBoardServer::tool(DeleteTaskTool::class, [
        'task_id' => $orphan->id,
    ]);

    $response->assertOk();

    $this->assertDatabaseMissing('activities', ['id' => $orphan->id]);
    $this->assertDatabaseMissing('time_entries', ['activity_id' => $orphan->id]);

    $this->assertDatabaseHas('activities', ['id' => $eligible->id]);
    expect(TimeEntry::query()->where('activity_id', $eligible->id)->count())->toBe(2);
});

// TaskSession: CreateTaskTool session tests
test('create-task accepts session_prompt', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Session Task',
        'session_prompt' => 'Implement push notifications with Firebase.',
    ]);

    $response->assertOk();
    $response->assertSee('"is_session_task": true');

    $this->assertDatabaseHas('activities', [
        'title' => 'Session Task',
        'session_prompt' => 'Implement push notifications with Firebase.',
    ]);
});

// TaskSession: UpdateTaskTool session tests
test('update-task accepts session_prompt and session_result', function () {
    $task = Activity::factory()->create();

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'session_prompt' => 'Refactor auth service to use Strategy pattern.',
        'session_result' => 'Refactored successfully. 12 tests passing.',
    ]);

    $response->assertOk();
    $response->assertSee('"is_session_task": true');

    $task->refresh();
    expect($task->session_prompt)->toBe('Refactor auth service to use Strategy pattern.');
    expect($task->session_result)->toBe('Refactored successfully. 12 tests passing.');
});

// TaskSession: GetTaskTool session tests
test('get-task includes session_summary', function () {
    $task = Activity::factory()->session()->create();

    $response = SoloBoardServer::tool(GetTaskTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('session_summary');
    $response->assertSee('"is_session": true');
});

// GitHub mirror tests (issue #86)
test('create-task persists github fields and feature_id', function () {
    $feature = Activity::factory()->epic()->create();

    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Mirrored Task',
        'github_issue_number' => 200,
        'github_synced_hash' => 'task-hash-1',
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();
    $response->assertSee('"github_issue_number": 200');

    $this->assertDatabaseHas('activities', [
        'title' => 'Mirrored Task',
        'github_issue_number' => 200,
        'github_synced_hash' => 'task-hash-1',
        'parent_id' => $feature->id,
    ]);
});

test('create-task upserts by github_issue_number without duplicating', function () {
    SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'First Task Title',
        'github_issue_number' => 201,
        'status' => 'todo',
    ]);

    SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Rewritten Task Title',
        'github_issue_number' => 201,
        'status' => 'backlog',
    ]);

    expect(Activity::query()->where('github_issue_number', 201)->count())->toBe(1);

    $this->assertDatabaseHas('activities', [
        'github_issue_number' => 201,
        'title' => 'Rewritten Task Title',
        'status' => 'backlog',
    ]);
});

test('create-task maps closed issue status to done', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Closed Issue Task',
        'github_issue_number' => 202,
        'status' => 'done',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "done"');

    $task = Activity::query()->where('github_issue_number', 202)->first();
    expect($task->status)->toBe(ActivityStatus::Done);
    expect($task->completed_at)->not->toBeNull();
});

test('update-task persists github fields and feature_id', function () {
    $feature = Activity::factory()->epic()->create();
    $task = Activity::factory()->create();

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'github_issue_number' => 9,
        'github_synced_hash' => 'task-hash-xyz',
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();
    $response->assertSee('"github_issue_number": 9');

    $this->assertDatabaseHas('activities', [
        'id' => $task->id,
        'github_issue_number' => 9,
        'github_synced_hash' => 'task-hash-xyz',
        'parent_id' => $feature->id,
    ]);
});

test('list-tasks and get-task expose github fields and feature_id', function () {
    $feature = Activity::factory()->epic()->create();
    $task = Activity::factory()->create([
        'github_issue_number' => 654,
        'github_synced_hash' => 'task-list-hash',
        'parent_id' => $feature->id,
    ]);

    $list = SoloBoardServer::tool(ListTasksTool::class, []);
    $list->assertOk();
    $list->assertSee('"github_issue_number": 654');
    $list->assertSee('task-list-hash');

    $get = SoloBoardServer::tool(GetTaskTool::class, ['task_id' => $task->id]);
    $get->assertOk();
    $get->assertSee('"github_issue_number": 654');
    $get->assertSee('task-list-hash');
});
