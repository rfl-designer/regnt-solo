<?php

use App\Enums\TaskStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\DeleteTaskTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;

// ListTasksTool tests
test('list-tasks returns all tasks', function () {
    Task::factory()->count(3)->create();

    $response = SoloBoardServer::tool(ListTasksTool::class, []);

    $response->assertOk();
});

test('list-tasks filters by status', function () {
    Task::factory()->create(['status' => TaskStatus::Todo]);
    Task::factory()->create(['status' => TaskStatus::Done]);

    $response = SoloBoardServer::tool(ListTasksTool::class, [
        'status' => 'todo',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "todo"');
    $response->assertDontSee('"status": "done"');
});

test('list-tasks filters by project slug', function () {
    $project = Project::factory()->create(['slug' => 'my-project']);
    Task::factory()->create(['project_id' => $project->id]);
    Task::factory()->create(['project_id' => null]);

    $response = SoloBoardServer::tool(ListTasksTool::class, [
        'project_slug' => 'my-project',
    ]);

    $response->assertOk();
    $response->assertSee($project->name);
});

test('list-tasks respects limit', function () {
    Task::factory()->count(5)->create();

    $response = SoloBoardServer::tool(ListTasksTool::class, [
        'limit' => 2,
    ]);

    $response->assertOk();
});

// GetTaskTool tests
test('get-task returns task with relationships', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);
    TimeEntry::factory()->create(['task_id' => $task->id]);

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

    $this->assertDatabaseHas('tasks', [
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

    $this->assertDatabaseHas('tasks', [
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
    $task = Task::factory()->create(['title' => 'Old Title']);

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'title' => 'New Title',
        'priority' => 'urgent',
    ]);

    $response->assertOk();
    $response->assertSee('New Title');

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'New Title',
        'priority' => 'urgent',
    ]);
});

test('update-task calls markAsDone when status changes to done', function () {
    $task = Task::factory()->doing()->create();
    $entry = TimeEntry::factory()->running()->create(['task_id' => $task->id]);

    $response = SoloBoardServer::tool(UpdateTaskTool::class, [
        'task_id' => $task->id,
        'status' => 'done',
    ]);

    $response->assertOk();

    $task->refresh();
    $entry->refresh();

    expect($task->status)->toBe(TaskStatus::Done);
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
    $task = Task::factory()->create();
    TimeEntry::factory()->count(2)->create(['task_id' => $task->id]);

    $response = SoloBoardServer::tool(DeleteTaskTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('"deleted": true');

    $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    $this->assertDatabaseCount('time_entries', 0);
});

test('delete-task fails for non-existent task', function () {
    $response = SoloBoardServer::tool(DeleteTaskTool::class, [
        'task_id' => 999,
    ]);

    $response->assertHasErrors();
});
