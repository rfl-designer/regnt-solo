<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Activity;
use App\Models\Project;

// ListTasksTool tests
test('list-tasks returns only personal tasks', function () {
    Activity::factory()->task()->create(['title' => 'Task Alpha']);
    Activity::factory()->epic()->create(['title' => 'Epic Beta']);
    Activity::factory()->issue()->create(['title' => 'Issue Gamma']);
    Activity::factory()->draft()->create(['title' => 'Draft Delta']);

    $response = SoloBoardServer::tool(ListTasksTool::class, []);

    $response->assertOk();
    $response->assertSee('Task Alpha');
    $response->assertDontSee('Epic Beta');
    $response->assertDontSee('Issue Gamma');
    $response->assertDontSee('Draft Delta');
});

test('list-tasks filters by status', function () {
    Activity::factory()->task()->create(['status' => ActivityStatus::Todo]);
    Activity::factory()->task()->create(['status' => ActivityStatus::Backlog]);

    $response = SoloBoardServer::tool(ListTasksTool::class, [
        'status' => 'todo',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "todo"');
    $response->assertDontSee('"status": "backlog"');
});

test('list-tasks filters by project id', function () {
    $project = Project::factory()->create();
    Activity::factory()->task()->create(['project_id' => $project->id, 'title' => 'Project Task']);
    Activity::factory()->task()->create(['project_id' => null, 'title' => 'Loose Task']);

    $response = SoloBoardServer::tool(ListTasksTool::class, [
        'project_id' => $project->id,
    ]);

    $response->assertOk();
    $response->assertSee('Project Task');
    $response->assertDontSee('Loose Task');
});

test('list-tasks does not expose github fields', function () {
    Activity::factory()->task()->create(['title' => 'Plain Task']);

    $response = SoloBoardServer::tool(ListTasksTool::class, []);

    $response->assertOk();
    $response->assertDontSee('github_issue_number');
    $response->assertDontSee('github_synced_hash');
});

// CreateTaskTool tests
test('create-task creates a personal task with defaults', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'New MCP Task',
    ]);

    $response->assertOk();
    $response->assertSee('New MCP Task');
    $response->assertSee('"status": "inbox"');
    $response->assertSee('"priority": "medium"');

    $this->assertDatabaseHas('activities', [
        'title' => 'New MCP Task',
        'type' => ActivityType::Task->value,
        'status' => 'inbox',
        'priority' => 'medium',
        'project_id' => null,
        'parent_id' => null,
    ]);
});

test('create-task accepts an optional project and parent', function () {
    $project = Project::factory()->create();
    $epic = Activity::factory()->epic()->create();

    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Hanging Task',
        'project_id' => $project->id,
        'parent_id' => $epic->id,
        'status' => 'todo',
        'priority' => 'high',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('activities', [
        'title' => 'Hanging Task',
        'type' => ActivityType::Task->value,
        'project_id' => $project->id,
        'parent_id' => $epic->id,
        'status' => 'todo',
        'priority' => 'high',
    ]);
});

test('create-task marks done when status is done', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Done Task',
        'status' => 'done',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "done"');

    $task = Activity::query()->tasks()->where('title', 'Done Task')->first();
    expect($task->status)->toBe(ActivityStatus::Done);
    expect($task->completed_at)->not->toBeNull();
});

test('create-task fails without title', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, []);

    $response->assertHasErrors();
});
