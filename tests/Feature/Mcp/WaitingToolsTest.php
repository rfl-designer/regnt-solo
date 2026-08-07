<?php

use App\Enums\ActivityStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\UpdateEpicTool;
use App\Mcp\Tools\UpdateIssueTool;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;

test('create-task auto-fills waiting_for from the effective client for a client-side wait', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);

    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Aguardando aprovação do cliente',
        'project_id' => $project->id,
        'status' => 'awaiting_approval',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "awaiting_approval"');
    $response->assertSee('"waiting_for": "Acme Corp"');
});

test('create-task moving into the internal wait without waiting_for is refused, same as UI', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Vai esperar alguém',
        'status' => 'waiting',
    ]);

    $response->assertHasErrors();
});

test('create-task moving into the internal wait with waiting_for succeeds', function () {
    $response = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Vai esperar o designer',
        'status' => 'waiting',
        'waiting_for' => 'Designer',
    ]);

    $response->assertOk();
    $response->assertSee('"waiting_for": "Designer"');

    $task = Activity::where('title', 'Vai esperar o designer')->firstOrFail();
    expect($task->waiting_since)->not->toBeNull();
});

test('update-issue moving into a client-side wait with no effective client is refused with the domain message', function () {
    $issue = Activity::factory()->issue()->create(['status' => ActivityStatus::Todo]);

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'status' => 'awaiting_validation',
    ]);

    $response->assertHasErrors();

    expect($issue->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('update-issue returns waiting_for and waiting_since', function () {
    $issue = Activity::factory()->issue()->waiting('QA')->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'title' => 'Título atualizado',
    ]);

    $response->assertOk();
    $response->assertSee('"waiting_for": "QA"');
});

test('update-epic accepts and enforces waiting_for for the internal wait', function () {
    $epic = Activity::factory()->epic()->create();

    $refused = SoloBoardServer::tool(UpdateEpicTool::class, [
        'epic_id' => $epic->id,
        'status' => 'waiting',
    ]);
    $refused->assertHasErrors();

    $accepted = SoloBoardServer::tool(UpdateEpicTool::class, [
        'epic_id' => $epic->id,
        'status' => 'waiting',
        'waiting_for' => 'Suporte',
    ]);
    $accepted->assertOk();
    $accepted->assertSee('"waiting_for": "Suporte"');
});
