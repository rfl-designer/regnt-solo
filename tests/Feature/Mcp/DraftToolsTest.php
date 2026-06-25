<?php

use App\Enums\ActivityType;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateDraftTool;
use App\Mcp\Tools\DeleteIssueTool;
use App\Mcp\Tools\ListDraftsTool;
use App\Mcp\Tools\ListEpicsTool;
use App\Mcp\Tools\ListIssuesTool;
use App\Mcp\Tools\PromoteDraftTool;
use App\Models\Activity;

// ListDraftsTool tests
test('list-drafts returns only drafts', function () {
    Activity::factory()->draft()->create(['title' => 'Draft Alpha']);
    Activity::factory()->epic()->create(['title' => 'Epic Beta']);
    Activity::factory()->issue()->create(['title' => 'Issue Gamma']);
    Activity::factory()->task()->create(['title' => 'Task Delta']);

    $response = SoloBoardServer::tool(ListDraftsTool::class, []);

    $response->assertOk();
    $response->assertSee('Draft Alpha');
    $response->assertDontSee('Epic Beta');
    $response->assertDontSee('Issue Gamma');
    $response->assertDontSee('Task Delta');
});

test('list-drafts exposes title and note but no status or github fields', function () {
    Activity::factory()->draft()->create(['title' => 'Plain Draft', 'description' => 'Some idea note']);

    $response = SoloBoardServer::tool(ListDraftsTool::class, []);

    $response->assertOk();
    $response->assertSee('Plain Draft');
    $response->assertSee('Some idea note');
    $response->assertDontSee('"status"');
    $response->assertDontSee('github_issue_number');
    $response->assertDontSee('github_synced_hash');
});

// CreateDraftTool tests
test('create-draft creates a draft with title and note', function () {
    $response = SoloBoardServer::tool(CreateDraftTool::class, [
        'title' => 'New idea',
        'note' => 'Maybe a calendar view',
    ]);

    $response->assertOk();
    $response->assertSee('New idea');
    $response->assertSee('Maybe a calendar view');

    $this->assertDatabaseHas('activities', [
        'title' => 'New idea',
        'description' => 'Maybe a calendar view',
        'type' => ActivityType::Draft->value,
        'status' => null,
        'github_issue_number' => null,
    ]);
});

test('create-draft works with just a title', function () {
    $response = SoloBoardServer::tool(CreateDraftTool::class, [
        'title' => 'Bare idea',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('activities', [
        'title' => 'Bare idea',
        'type' => ActivityType::Draft->value,
        'status' => null,
    ]);
});

test('create-draft fails without title', function () {
    $response = SoloBoardServer::tool(CreateDraftTool::class, []);

    $response->assertHasErrors();
});

// PromoteDraftTool tests
test('promote-draft hands off to /to-prd and removes the draft', function () {
    $draft = Activity::factory()->draft()->create([
        'title' => 'Promote me',
        'description' => 'Idea worth a PRD',
    ]);

    $response = SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $draft->id,
        'target' => 'prd',
    ]);

    $response->assertOk();
    $response->assertSee('/to-prd');
    $response->assertSee('Promote me');
    $response->assertSee('Idea worth a PRD');

    $this->assertDatabaseMissing('activities', ['id' => $draft->id]);
});

test('promote-draft hands off to /to-issues when target is issues', function () {
    $draft = Activity::factory()->draft()->create(['title' => 'Slice idea']);

    $response = SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $draft->id,
        'target' => 'issues',
    ]);

    $response->assertOk();
    $response->assertSee('/to-issues');

    $this->assertDatabaseMissing('activities', ['id' => $draft->id]);
});

test('promote-draft refuses a non-draft activity', function () {
    $epic = Activity::factory()->epic()->create();

    expect(fn () => SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $epic->id,
        'target' => 'prd',
    ]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->assertDatabaseHas('activities', ['id' => $epic->id]);
});

test('promote-draft requires a valid target', function () {
    $draft = Activity::factory()->draft()->create();

    $response = SoloBoardServer::tool(PromoteDraftTool::class, [
        'draft_id' => $draft->id,
        'target' => 'something-else',
    ]);

    $response->assertHasErrors();
    $this->assertDatabaseHas('activities', ['id' => $draft->id]);
});

// Sync isolation: the roadmap tools the sync relies on never touch drafts
test('list-issues and list-epics never return drafts', function () {
    Activity::factory()->draft()->create(['title' => 'Hidden Draft']);

    SoloBoardServer::tool(ListIssuesTool::class, [])
        ->assertOk()
        ->assertDontSee('Hidden Draft');

    SoloBoardServer::tool(ListEpicsTool::class, [])
        ->assertOk()
        ->assertDontSee('Hidden Draft');
});

test('sync reconciliation (delete-issue) never deletes a draft', function () {
    $draft = Activity::factory()->draft()->create(['title' => 'Survivor Draft']);

    expect(fn () => SoloBoardServer::tool(DeleteIssueTool::class, [
        'issue_id' => $draft->id,
    ]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->assertDatabaseHas('activities', ['id' => $draft->id]);
});
