<?php

use App\Mcp\Prompts\SessionPlanningPrompt;
use App\Mcp\Servers\SoloBoardServer;
use App\Models\Activity;
use App\Models\Project;

test('session planning prompt returns context for session task', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->session()->doing()->create([
        'project_id' => $project->id,
    ]);

    $response = SoloBoardServer::prompt(SessionPlanningPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('Session Prompt');
    $response->assertSee($task->title);
    $response->assertSee($project->name);
});

test('session planning prompt fails for non-existent task', function () {
    SoloBoardServer::prompt(SessionPlanningPrompt::class, [
        'task_id' => '99999',
    ]);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

test('session planning prompt includes related tasks', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->session()->doing()->create([
        'project_id' => $project->id,
    ]);
    $relatedTask = Activity::factory()->todo()->create([
        'project_id' => $project->id,
    ]);

    $response = SoloBoardServer::prompt(SessionPlanningPrompt::class, [
        'task_id' => (string) $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('Related Tasks');
    $response->assertSee($relatedTask->title);
});
