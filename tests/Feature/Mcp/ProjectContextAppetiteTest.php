<?php

use App\Enums\ActivityStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\GetProjectContextTool;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\Project;

/**
 * O apetite no contexto do projeto (issue #152): o agente que vai planejar uma
 * sessão descobre, junto com o resto, quanto do orçamento da aposta já foi.
 */
function contextBet(Project $project, ?int $appetiteDays, float $days, string $title): Activity
{
    $epic = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::Backlog,
        'appetite_days' => $appetiteDays,
        'title' => $title,
    ]);

    ActivityStatusChange::query()->where('activity_id', $epic->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $epic->id,
        'from_status' => null,
        'to_status' => ActivityStatus::AwaitingApproval,
        'changed_at' => now()->copy()->subDays($days + 1),
    ]);

    ActivityStatusChange::factory()->create([
        'activity_id' => $epic->id,
        'from_status' => ActivityStatus::AwaitingApproval,
        'to_status' => ActivityStatus::Doing,
        'changed_at' => now()->copy()->subDays($days),
    ]);

    return $epic->refresh();
}

test('get-project-context carries the apetite, the consumption and the status', function () {
    $project = Project::factory()->create();
    contextBet($project, appetiteDays: 14, days: 9, title: 'Aposta viva');

    $response = SoloBoardServer::tool(GetProjectContextTool::class, [
        'project_slug' => $project->slug,
    ]);

    $response->assertOk();
    $response->assertSee('"days": 14');
    $response->assertSee('"consumed_days": 9');
    $response->assertSee('"status": "ok"');
});

test('get-project-context marks an exceeded bet and a budgetless one apart', function () {
    $project = Project::factory()->create();
    contextBet($project, appetiteDays: 5, days: 12, title: 'Estourada');
    contextBet($project, appetiteDays: null, days: 30, title: 'Sem apetite');

    $response = SoloBoardServer::tool(GetProjectContextTool::class, [
        'project_slug' => $project->slug,
    ]);

    $response->assertOk();
    $response->assertSee('"status": "exceeded"');
    $response->assertSee('"status": "no_appetite"');
});

test('get-project-context says not_started while the spec was never approved', function () {
    $project = Project::factory()->create();
    Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::Backlog,
        'appetite_days' => 7,
        'title' => 'Ainda não apostada',
    ]);

    $response = SoloBoardServer::tool(GetProjectContextTool::class, [
        'project_slug' => $project->slug,
    ]);

    $response->assertOk();
    $response->assertSee('"status": "not_started"');
});

test('an issue carries no apetite at all — it is not a bet', function () {
    $project = Project::factory()->create();
    Activity::factory()->issue()->create([
        'project_id' => $project->id,
        'status' => ActivityStatus::Todo,
        'title' => 'Uma fatia qualquer',
    ]);

    $response = SoloBoardServer::tool(GetProjectContextTool::class, [
        'project_slug' => $project->slug,
    ]);

    $response->assertOk();
    $response->assertSee('"appetite": null');
});
