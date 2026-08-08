<?php

use App\Enums\ActivityStatus;
use App\Mcp\Resources\ProjectOverviewResource;
use App\Mcp\Servers\SoloBoardServer;
use App\Models\Activity;
use App\Models\Project;
use App\Models\TimeEntry;

// ProjectOverviewResource tests
test('overview resource returns project data', function () {
    $project = Project::factory()->create();
    Activity::factory()->count(2)->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::Todo,
    ]);

    $response = SoloBoardServer::resource(ProjectOverviewResource::class);

    $response->assertOk();
    $response->assertSee($project->name);
});

test('overview resource shows running timer', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    $response = SoloBoardServer::resource(ProjectOverviewResource::class);

    $response->assertOk();
    $response->assertSee($task->title);
});

test('overview resource shows overdue tasks', function () {
    $task = Activity::factory()->overdue()->create();

    $response = SoloBoardServer::resource(ProjectOverviewResource::class);

    $response->assertOk();
    $response->assertSee($task->title);
});

test('overview resource shows hours worked today', function () {
    $task = Activity::factory()->create();
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => now()->subHours(2),
        'stopped_at' => now(),
    ]);

    $response = SoloBoardServer::resource(ProjectOverviewResource::class);

    $response->assertOk();
    $response->assertSee('hours_worked_today');
});

// O prompt `daily-planning` foi removido com o Daily Planner (issue #147):
// o ritual matinal é um ato na aplicação, e o que um cliente MCP pode ler
// dele está em `get-ritual-status` (ver Mcp/RitualStatusToolTest.php).
