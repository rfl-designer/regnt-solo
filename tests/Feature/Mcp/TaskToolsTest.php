<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Activity;
use App\Models\Client;
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

test('list-tasks resolves the effective client from a direct link', function () {
    $client = Client::factory()->create(['name' => 'Direct Client Co']);
    Activity::factory()->task()->create(['project_id' => null, 'client_id' => $client->id, 'title' => 'Direct Client Task']);

    $response = SoloBoardServer::tool(ListTasksTool::class, []);

    $response->assertOk();
    $response->assertSee('"client": "Direct Client Co"');
});

test('list-tasks resolves the effective client inherited via the project', function () {
    $client = Client::factory()->create(['name' => 'Project Client Co']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    Activity::factory()->task()->create(['project_id' => $project->id, 'title' => 'Project Client Task']);

    $response = SoloBoardServer::tool(ListTasksTool::class, []);

    $response->assertOk();
    $response->assertSee('"client": "Project Client Co"');
});

// CreateTaskTool tests
test('create-task creates a personal task with defaults', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'New MCP Task',
    ]);

    $response->assertOk();
    $response->assertSee('New MCP Task');
    $response->assertSee('"status": "inbox"');
    $response->assertSee('"service_class": "standard"');

    $this->assertDatabaseHas('activities', [
        'title' => 'New MCP Task',
        'type' => ActivityType::Task->value,
        'status' => 'inbox',
        'service_class' => 'standard',
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
        'service_class' => 'emergency',
        'emergency_reason' => 'Produção fora do ar',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('activities', [
        'title' => 'Hanging Task',
        'type' => ActivityType::Task->value,
        'project_id' => $project->id,
        'parent_id' => $epic->id,
        'status' => 'todo',
        'service_class' => 'emergency',
    ]);
});

test('create-task refuses fixed_date without a due date', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Fixed date task',
        'service_class' => 'fixed_date',
    ]);

    $response->assertHasErrors([FixedDateRequiresDueDateException::MESSAGE]);

    $this->assertDatabaseMissing('activities', [
        'title' => 'Fixed date task',
    ]);
});

test('create-task accepts fixed_date with a due date', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Fixed date task with due date',
        'service_class' => 'fixed_date',
        'due_date' => '2026-09-01',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('activities', [
        'title' => 'Fixed date task with due date',
        'service_class' => 'fixed_date',
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

test('create-task accepts an optional direct client link', function () {
    $client = Client::factory()->create(['name' => 'MCP Client Co']);

    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Client Linked Task',
        'client_id' => $client->id,
    ]);

    $response->assertOk();
    $response->assertSee('"client": "MCP Client Co"');

    $this->assertDatabaseHas('activities', [
        'title' => 'Client Linked Task',
        'client_id' => $client->id,
        'project_id' => null,
    ]);
});

test('create-task with both project and client keeps only the project link', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create();

    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Project Wins Task',
        'project_id' => $project->id,
        'client_id' => $client->id,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('activities', [
        'title' => 'Project Wins Task',
        'project_id' => $project->id,
        'client_id' => null,
    ]);
});

test('create-task fails with an unknown client id', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Bad Client Task',
        'client_id' => 999999,
    ]);

    $response->assertHasErrors();
});
