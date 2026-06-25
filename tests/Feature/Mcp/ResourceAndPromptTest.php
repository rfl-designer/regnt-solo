<?php

use App\Enums\ActivityStatus;
use App\Mcp\Prompts\DailyPlanningPrompt;
use App\Mcp\Resources\ProjectOverviewResource;
use App\Mcp\Servers\SoloBoardServer;
use App\Models\Activity;
use App\Models\DailyPlan;
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

// DailyPlanningPrompt tests
test('daily planning prompt returns context', function () {
    Activity::factory()->overdue()->create();
    Activity::factory()->doing()->create();
    Activity::factory()->todo()->create();

    $response = SoloBoardServer::prompt(DailyPlanningPrompt::class, []);

    $response->assertOk();
    $response->assertSee('Overdue Tasks');
    $response->assertSee('In Progress');
});

test('daily planning prompt accepts focus project', function () {
    $project = Project::factory()->create(['slug' => 'my-project']);
    Activity::factory()->todo()->create(['project_id' => $project->id]);

    $response = SoloBoardServer::prompt(DailyPlanningPrompt::class, [
        'focus_project' => 'my-project',
    ]);

    $response->assertOk();
    $response->assertSee($project->name);
});

test('daily planning prompt includes planned tasks', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Activity::factory()->create();
    $plan->tasks()->attach($task->id, ['sort_order' => 1]);

    $response = SoloBoardServer::prompt(DailyPlanningPrompt::class, []);

    $response->assertOk();
    $response->assertSee('Already Planned for Today');
    $response->assertSee($task->title);
});
