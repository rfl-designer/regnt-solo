<?php

use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\ListProjectsTool;
use App\Models\Activity;
use App\Models\Project;

// As tools do plano diário (today-plan, suggest-tasks, add-to-plan) saíram
// com o Daily Planner (issue #147). O que um cliente MCP lê do dia agora
// está em `get-ritual-status` — ver Mcp/RitualStatusToolTest.php.

// ListProjectsTool tests
test('list-projects returns active projects by default', function () {
    $active = Project::factory()->create(['status' => 'active']);
    $archived = Project::factory()->archived()->create();

    $response = SoloBoardServer::tool(ListProjectsTool::class, []);

    $response->assertOk();
    $response->assertSee($active->name);
    $response->assertDontSee($archived->name);
});

test('list-projects filters by status', function () {
    $active = Project::factory()->create(['status' => 'active']);
    $paused = Project::factory()->paused()->create();

    $response = SoloBoardServer::tool(ListProjectsTool::class, [
        'status' => 'paused',
    ]);

    $response->assertOk();
    $response->assertSee($paused->name);
    $response->assertDontSee($active->name);
});

test('list-projects includes active task count', function () {
    $project = Project::factory()->create();
    Activity::factory()->count(3)->create(['project_id' => $project->id, 'status' => 'todo']);
    Activity::factory()->done()->create(['project_id' => $project->id]);

    $response = SoloBoardServer::tool(ListProjectsTool::class, []);

    $response->assertOk();
    $response->assertSee('"active_tasks_count": 3');
});
