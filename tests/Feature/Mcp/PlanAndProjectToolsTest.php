<?php

use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\AddToPlanTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\SuggestTasksTool;
use App\Mcp\Tools\TodayPlanTool;
use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\Project;

// TodayPlanTool tests
test('today-plan creates plan if not exists', function () {
    $this->assertDatabaseCount('daily_plans', 0);

    $response = SoloBoardServer::tool(TodayPlanTool::class, []);

    $response->assertOk();
    $response->assertSee(now()->toDateString());

    $this->assertDatabaseCount('daily_plans', 1);
});

test('today-plan returns existing plan with tasks', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Activity::factory()->create();
    $plan->tasks()->attach($task->id, ['sort_order' => 1]);

    $response = SoloBoardServer::tool(TodayPlanTool::class, []);

    $response->assertOk();
    $response->assertSee($task->title);
    $response->assertSee('"total_tasks": 1');
});

// SuggestTasksTool tests
test('suggest-tasks prioritizes overdue tasks', function () {
    $overdue = Activity::factory()->overdue()->create(['title' => 'Overdue Task']);
    $todo = Activity::factory()->todo()->create(['title' => 'Todo Task']);

    $response = SoloBoardServer::tool(SuggestTasksTool::class, []);

    $response->assertOk();
    $response->assertSee('Overdue Task');
    $response->assertSee('Todo Task');
});

test('suggest-tasks excludes tasks already in plan', function () {
    $plan = DailyPlan::factory()->today()->create();
    $inPlan = Activity::factory()->todo()->create(['title' => 'Already Planned']);
    $notInPlan = Activity::factory()->todo()->create(['title' => 'Not Planned']);
    $plan->tasks()->attach($inPlan->id, ['sort_order' => 1]);

    $response = SoloBoardServer::tool(SuggestTasksTool::class, []);

    $response->assertOk();
    $response->assertSee('Not Planned');
    $response->assertDontSee('Already Planned');
});

test('suggest-tasks returns max 10 suggestions', function () {
    Activity::factory()->todo()->count(15)->create();

    $response = SoloBoardServer::tool(SuggestTasksTool::class, []);

    $response->assertOk();
    $response->assertSee('"count": 10');
});

// AddToPlanTool tests
test('add-to-plan adds task to today plan', function () {
    $task = Activity::factory()->create();

    $response = SoloBoardServer::tool(AddToPlanTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('"added": true');
    $response->assertSee($task->title);

    $this->assertDatabaseHas('daily_plan_activity', [
        'activity_id' => $task->id,
    ]);
});

test('add-to-plan prevents duplicate additions', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Activity::factory()->create();
    $plan->tasks()->attach($task->id, ['sort_order' => 1]);

    $response = SoloBoardServer::tool(AddToPlanTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('"added": false');
    $response->assertSee('already in today');
});

test('add-to-plan fails for non-existent task', function () {
    $response = SoloBoardServer::tool(AddToPlanTool::class, [
        'task_id' => 999,
    ]);

    $response->assertHasErrors();
});

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
