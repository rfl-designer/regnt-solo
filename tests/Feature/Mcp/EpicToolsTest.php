<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityType;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateEpicTool;
use App\Mcp\Tools\ListEpicsTool;
use App\Mcp\Tools\UpdateEpicTool;
use App\Models\Activity;
use App\Models\Project;

// ListEpicsTool tests
test('list-epics returns only epics', function () {
    Activity::factory()->epic()->create(['title' => 'Epic Alpha']);
    Activity::factory()->issue()->create(['title' => 'Issue Beta']);
    Activity::factory()->task()->create(['title' => 'Task Gamma']);
    Activity::factory()->draft()->create(['title' => 'Draft Delta']);

    $response = SoloBoardServer::tool(ListEpicsTool::class, []);

    $response->assertOk();
    $response->assertSee('Epic Alpha');
    $response->assertDontSee('Issue Beta');
    $response->assertDontSee('Task Gamma');
    $response->assertDontSee('Draft Delta');
});

test('list-epics filters by project id', function () {
    $project = Project::factory()->create();
    Activity::factory()->epic()->create(['project_id' => $project->id, 'title' => 'Project Epic']);
    Activity::factory()->epic()->create(['project_id' => null, 'title' => 'Unassigned Epic']);

    $response = SoloBoardServer::tool(ListEpicsTool::class, [
        'project_id' => $project->id,
    ]);

    $response->assertOk();
    $response->assertSee('Project Epic');
    $response->assertDontSee('Unassigned Epic');
});

test('list-epics filters by status', function () {
    Activity::factory()->epic()->create(['title' => 'Backlog Epic', 'status' => 'backlog']);
    Activity::factory()->epic()->create(['title' => 'Doing Epic', 'status' => 'doing']);

    $response = SoloBoardServer::tool(ListEpicsTool::class, [
        'status' => 'doing',
    ]);

    $response->assertOk();
    $response->assertSee('Doing Epic');
    $response->assertDontSee('Backlog Epic');
});

test('list-epics returns progress and counts', function () {
    $epic = Activity::factory()->epic()->create(['title' => 'Progress Epic']);
    Activity::factory()->count(3)->done()->create(['parent_id' => $epic->id]);
    Activity::factory()->count(2)->todo()->create(['parent_id' => $epic->id]);

    $response = SoloBoardServer::tool(ListEpicsTool::class, []);

    $response->assertOk();
    $response->assertSee('"issues_count": 5');
    $response->assertSee('"completed_issues": 3');
    $response->assertSee('"progress": 60');
});

test('list-epics exposes github fields', function () {
    Activity::factory()->epic()->create([
        'github_issue_number' => 321,
        'github_synced_hash' => 'list-hash',
    ]);

    $response = SoloBoardServer::tool(ListEpicsTool::class, []);

    $response->assertOk();
    $response->assertSee('"github_issue_number": 321');
    $response->assertSee('list-hash');
});

test('list-epics fails with invalid project id', function () {
    $response = SoloBoardServer::tool(ListEpicsTool::class, [
        'project_id' => 999999,
    ]);

    $response->assertHasErrors();
});

// CreateEpicTool tests
test('create-epic creates a new epic with backlog status', function () {
    $response = SoloBoardServer::tool(CreateEpicTool::class, [
        'title' => 'User Authentication',
        'spec' => '## Overview\nImplement OAuth2 flow',
        'priority' => 'high',
    ]);

    $response->assertOk();
    $response->assertSee('User Authentication');
    $response->assertSee('"status": "backlog"');

    $this->assertDatabaseHas('activities', [
        'title' => 'User Authentication',
        'type' => ActivityType::Epic->value,
        'priority' => 'high',
        'status' => 'backlog',
    ]);
});

test('create-epic never accepts status (manual)', function () {
    $response = SoloBoardServer::tool(CreateEpicTool::class, [
        'title' => 'No Status Epic',
        'status' => 'done',
    ]);

    $response->assertOk();
    // The status param is ignored — the epic defaults to backlog.
    $response->assertSee('"status": "backlog"');

    $this->assertDatabaseHas('activities', [
        'title' => 'No Status Epic',
        'status' => 'backlog',
    ]);
});

test('create-epic assigns to project by id', function () {
    $project = Project::factory()->create();

    $response = SoloBoardServer::tool(CreateEpicTool::class, [
        'title' => 'Dashboard Widgets',
        'project_id' => $project->id,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('activities', [
        'title' => 'Dashboard Widgets',
        'type' => ActivityType::Epic->value,
        'project_id' => $project->id,
    ]);
});

test('create-epic uses default priority', function () {
    $response = SoloBoardServer::tool(CreateEpicTool::class, [
        'title' => 'Simple Epic',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('activities', [
        'title' => 'Simple Epic',
        'priority' => ActivityPriority::Medium->value,
    ]);
});

test('create-epic upserts by github_issue_number without duplicating', function () {
    SoloBoardServer::tool(CreateEpicTool::class, [
        'title' => 'First Title',
        'github_issue_number' => 100,
        'github_synced_hash' => 'hash-1',
    ]);

    $response = SoloBoardServer::tool(CreateEpicTool::class, [
        'title' => 'Rewritten Title',
        'github_issue_number' => 100,
        'github_synced_hash' => 'hash-2',
    ]);

    $response->assertOk();

    expect(Activity::query()->epics()->where('github_issue_number', 100)->count())->toBe(1);

    $this->assertDatabaseHas('activities', [
        'github_issue_number' => 100,
        'title' => 'Rewritten Title',
        'github_synced_hash' => 'hash-2',
    ]);
});

test('create-epic fails without title', function () {
    $response = SoloBoardServer::tool(CreateEpicTool::class, []);

    $response->assertHasErrors();
});

// UpdateEpicTool tests
test('update-epic updates title and spec', function () {
    $epic = Activity::factory()->epic()->create([
        'title' => 'Original Title',
        'spec' => 'Original spec',
    ]);

    $response = SoloBoardServer::tool(UpdateEpicTool::class, [
        'epic_id' => $epic->id,
        'title' => 'Updated Title',
        'spec' => 'Updated spec',
    ]);

    $response->assertOk();
    $response->assertSee('Updated Title');

    $epic->refresh();
    expect($epic->title)->toBe('Updated Title');
    expect($epic->spec)->toBe('Updated spec');
});

test('update-epic changes status manually', function () {
    $epic = Activity::factory()->epic()->create(['status' => 'backlog']);

    $response = SoloBoardServer::tool(UpdateEpicTool::class, [
        'epic_id' => $epic->id,
        'status' => 'doing',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "doing"');

    $this->assertDatabaseHas('activities', [
        'id' => $epic->id,
        'status' => 'doing',
    ]);
});

test('update-epic persists github fields', function () {
    $epic = Activity::factory()->epic()->create();

    $response = SoloBoardServer::tool(UpdateEpicTool::class, [
        'epic_id' => $epic->id,
        'github_issue_number' => 7,
        'github_synced_hash' => 'hash-xyz',
    ]);

    $response->assertOk();
    $response->assertSee('"github_issue_number": 7');

    $this->assertDatabaseHas('activities', [
        'id' => $epic->id,
        'github_issue_number' => 7,
        'github_synced_hash' => 'hash-xyz',
    ]);
});

test('update-epic fails for non-existent epic', function () {
    $response = SoloBoardServer::tool(UpdateEpicTool::class, [
        'epic_id' => 999,
        'title' => 'Test',
    ]);

    $response->assertHasErrors();
});

// Layer guard: epic tools never touch the personal layer (Task/Draft)
test('update-epic rejects a non-epic activity', function () {
    $activities = [
        Activity::factory()->task()->create(),
        Activity::factory()->draft()->create(),
        Activity::factory()->issue()->create(),
    ];

    foreach ($activities as $activity) {
        $refused = false;

        try {
            SoloBoardServer::tool(UpdateEpicTool::class, [
                'epic_id' => $activity->id,
                'title' => 'Should not apply',
            ]);
        } catch (\Throwable $e) {
            $refused = true;
        }

        expect($refused)->toBeTrue();

        $activity->refresh();
        expect($activity->title)->not->toBe('Should not apply');
    }
});
