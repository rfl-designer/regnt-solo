<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateIssueTool;
use App\Mcp\Tools\DeleteIssueTool;
use App\Mcp\Tools\ListIssuesTool;
use App\Mcp\Tools\UpdateIssueTool;
use App\Models\Activity;
use App\Models\Project;
use App\Models\TimeEntry;

// ListIssuesTool tests
test('list-issues returns only issues', function () {
    Activity::factory()->issue()->create(['title' => 'Issue Alpha']);
    Activity::factory()->epic()->create(['title' => 'Epic Beta']);
    Activity::factory()->task()->create(['title' => 'Task Gamma']);
    Activity::factory()->draft()->create(['title' => 'Draft Delta']);

    $response = SoloBoardServer::tool(ListIssuesTool::class, []);

    $response->assertOk();
    $response->assertSee('Issue Alpha');
    $response->assertDontSee('Epic Beta');
    $response->assertDontSee('Task Gamma');
    $response->assertDontSee('Draft Delta');
});

test('list-issues filters by status', function () {
    Activity::factory()->issue()->create(['status' => ActivityStatus::Todo]);
    Activity::factory()->issue()->create(['status' => ActivityStatus::Backlog]);

    $response = SoloBoardServer::tool(ListIssuesTool::class, [
        'status' => 'todo',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "todo"');
    $response->assertDontSee('"status": "backlog"');
});

test('list-issues filters by project id', function () {
    $project = Project::factory()->create();
    Activity::factory()->issue()->create(['project_id' => $project->id, 'title' => 'Project Issue']);
    Activity::factory()->issue()->create(['project_id' => null, 'title' => 'Loose Issue']);

    $response = SoloBoardServer::tool(ListIssuesTool::class, [
        'project_id' => $project->id,
    ]);

    $response->assertOk();
    $response->assertSee('Project Issue');
    $response->assertDontSee('Loose Issue');
});

test('list-issues exposes github fields and parent_id', function () {
    $epic = Activity::factory()->epic()->create();
    Activity::factory()->issue()->create([
        'github_issue_number' => 654,
        'github_synced_hash' => 'issue-list-hash',
        'parent_id' => $epic->id,
    ]);

    $response = SoloBoardServer::tool(ListIssuesTool::class, []);

    $response->assertOk();
    $response->assertSee('"github_issue_number": 654');
    $response->assertSee('issue-list-hash');
    $response->assertSee('"parent_id": '.$epic->id);
});

// CreateIssueTool tests
test('create-issue creates issue with defaults', function () {
    $response = SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'New MCP Issue',
    ]);

    $response->assertOk();
    $response->assertSee('New MCP Issue');
    $response->assertSee('"status": "inbox"');
    $response->assertSee('"service_class": "standard"');

    $this->assertDatabaseHas('activities', [
        'title' => 'New MCP Issue',
        'type' => ActivityType::Issue->value,
        'status' => 'inbox',
        'service_class' => 'standard',
    ]);
});

test('create-issue accepts project_id and parent_id', function () {
    $project = Project::factory()->create();
    $epic = Activity::factory()->epic()->create();

    $response = SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'Slice Issue',
        'project_id' => $project->id,
        'parent_id' => $epic->id,
        'status' => 'todo',
        'service_class' => 'emergency',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('activities', [
        'title' => 'Slice Issue',
        'type' => ActivityType::Issue->value,
        'project_id' => $project->id,
        'parent_id' => $epic->id,
        'status' => 'todo',
        'service_class' => 'emergency',
    ]);
});

test('create-issue refuses fixed_date without a due date', function () {
    $response = SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'Fixed date issue',
        'service_class' => 'fixed_date',
    ]);

    $response->assertHasErrors();

    $this->assertDatabaseMissing('activities', [
        'title' => 'Fixed date issue',
    ]);
});

test('create-issue marks done when status is done', function () {
    $response = SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'Closed Issue',
        'github_issue_number' => 202,
        'status' => 'done',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "done"');

    $issue = Activity::query()->issues()->where('github_issue_number', 202)->first();
    expect($issue->status)->toBe(ActivityStatus::Done);
    expect($issue->completed_at)->not->toBeNull();
});

test('create-issue upserts by github_issue_number without duplicating', function () {
    SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'First Issue Title',
        'github_issue_number' => 201,
        'status' => 'todo',
    ]);

    SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'Rewritten Issue Title',
        'github_issue_number' => 201,
        'status' => 'backlog',
    ]);

    expect(Activity::query()->issues()->where('github_issue_number', 201)->count())->toBe(1);

    $this->assertDatabaseHas('activities', [
        'github_issue_number' => 201,
        'title' => 'Rewritten Issue Title',
        'status' => 'backlog',
    ]);
});

test('create-issue allows the same github_issue_number across different projects', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'Project A Issue Five',
        'project_id' => $projectA->id,
        'github_issue_number' => 5,
    ]);

    SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'Project B Issue Five',
        'project_id' => $projectB->id,
        'github_issue_number' => 5,
    ]);

    expect(Activity::query()->issues()->where('github_issue_number', 5)->count())->toBe(2);
    $this->assertDatabaseHas('activities', ['project_id' => $projectA->id, 'github_issue_number' => 5, 'title' => 'Project A Issue Five']);
    $this->assertDatabaseHas('activities', ['project_id' => $projectB->id, 'github_issue_number' => 5, 'title' => 'Project B Issue Five']);
});

test('create-issue upserts by github_issue_number scoped to project', function () {
    $project = Project::factory()->create();

    SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'Original Title',
        'project_id' => $project->id,
        'github_issue_number' => 7,
    ]);

    SoloBoardServer::tool(CreateIssueTool::class, [
        'title' => 'Resynced Title',
        'project_id' => $project->id,
        'github_issue_number' => 7,
    ]);

    expect(Activity::query()->issues()->where('project_id', $project->id)->where('github_issue_number', 7)->count())->toBe(1);
    $this->assertDatabaseHas('activities', ['project_id' => $project->id, 'github_issue_number' => 7, 'title' => 'Resynced Title']);
});

test('create-issue fails without title', function () {
    $response = SoloBoardServer::tool(CreateIssueTool::class, []);

    $response->assertHasErrors();
});

// UpdateIssueTool tests
test('update-issue updates fields, project and parent', function () {
    $project = Project::factory()->create();
    $epic = Activity::factory()->epic()->create();
    $issue = Activity::factory()->issue()->create(['title' => 'Old Title']);

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'title' => 'New Title',
        'service_class' => 'emergency',
        'project_id' => $project->id,
        'parent_id' => $epic->id,
    ]);

    $response->assertOk();
    $response->assertSee('New Title');

    $this->assertDatabaseHas('activities', [
        'id' => $issue->id,
        'title' => 'New Title',
        'service_class' => 'emergency',
        'project_id' => $project->id,
        'parent_id' => $epic->id,
    ]);
});

test('update-issue refuses fixed_date without a due date', function () {
    $issue = Activity::factory()->issue()->create(['due_date' => null]);

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'service_class' => 'fixed_date',
    ]);

    $response->assertHasErrors();

    $issue->refresh();
    expect($issue->service_class->value)->not->toBe('fixed_date');
});

test('update-issue marks done and stops timers when status changes to done', function () {
    $issue = Activity::factory()->issue()->create(['status' => ActivityStatus::Doing]);
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $issue->id]);

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'status' => 'done',
    ]);

    $response->assertOk();

    $issue->refresh();
    $entry->refresh();

    expect($issue->status)->toBe(ActivityStatus::Done);
    expect($issue->completed_at)->not->toBeNull();
    expect($entry->stopped_at)->not->toBeNull();
});

test('update-issue rejects status=done combined with an invalid fixed_date and rolls back all side effects', function () {
    $issue = Activity::factory()->issue()->create(['status' => ActivityStatus::Doing, 'due_date' => null]);
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $issue->id]);

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'status' => 'done',
        'service_class' => 'fixed_date',
    ]);

    $response->assertHasErrors();

    $issue->refresh();
    $entry->refresh();

    // markAsDone() runs before the guarded update in source order, but the
    // whole thing is one transaction — none of its side effects may stick
    // once the fixed_date/due_date combination is refused.
    expect($issue->status)->toBe(ActivityStatus::Doing);
    expect($issue->completed_at)->toBeNull();
    expect($issue->service_class->value)->not->toBe('fixed_date');
    expect($entry->stopped_at)->toBeNull();
});

test('update-issue persists github fields', function () {
    $issue = Activity::factory()->issue()->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'github_issue_number' => 9,
        'github_synced_hash' => 'issue-hash-xyz',
    ]);

    $response->assertOk();
    $response->assertSee('"github_issue_number": 9');

    $this->assertDatabaseHas('activities', [
        'id' => $issue->id,
        'github_issue_number' => 9,
        'github_synced_hash' => 'issue-hash-xyz',
    ]);
});

test('update-issue fails for non-existent issue', function () {
    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => 999,
        'title' => 'New Title',
    ]);

    $response->assertHasErrors();
});

test('update-issue rejects a non-issue activity', function () {
    $activities = [
        Activity::factory()->epic()->create(),
        Activity::factory()->task()->create(),
        Activity::factory()->draft()->create(),
    ];

    foreach ($activities as $activity) {
        $refused = false;

        try {
            SoloBoardServer::tool(UpdateIssueTool::class, [
                'issue_id' => $activity->id,
                'title' => 'Should not apply',
            ]);
        } catch (Throwable $e) {
            $refused = true;
        }

        expect($refused)->toBeTrue();

        $activity->refresh();
        expect($activity->title)->not->toBe('Should not apply');
    }
});

// DeleteIssueTool tests
test('delete-issue deletes issue and cascades time entries', function () {
    $issue = Activity::factory()->issue()->create();
    TimeEntry::factory()->count(2)->create(['activity_id' => $issue->id]);

    $response = SoloBoardServer::tool(DeleteIssueTool::class, [
        'issue_id' => $issue->id,
    ]);

    $response->assertOk();
    $response->assertSee('"deleted": true');
    $response->assertSee('"time_entries_deleted": 2');

    $this->assertDatabaseMissing('activities', ['id' => $issue->id]);
    $this->assertDatabaseCount('time_entries', 0);
});

test('delete-issue rejects a non-issue activity', function () {
    $activities = [
        Activity::factory()->epic()->create(),
        Activity::factory()->task()->create(),
        Activity::factory()->draft()->create(),
    ];

    foreach ($activities as $activity) {
        $refused = false;

        try {
            SoloBoardServer::tool(DeleteIssueTool::class, [
                'issue_id' => $activity->id,
            ]);
        } catch (Throwable $e) {
            $refused = true;
        }

        expect($refused)->toBeTrue();

        $this->assertDatabaseHas('activities', ['id' => $activity->id]);
    }
});

test('delete-issue fails for non-existent issue', function () {
    $response = SoloBoardServer::tool(DeleteIssueTool::class, [
        'issue_id' => 999,
    ]);

    $response->assertHasErrors();
});

// Reconciliation: dead mirrored issue removed, eligible ones preserved
test('reconciliation deletes the orphan mirrored issue and preserves eligible ones', function () {
    $eligible = Activity::factory()->issue()->create(['github_issue_number' => 300]);
    TimeEntry::factory()->count(2)->create(['activity_id' => $eligible->id]);

    $orphan = Activity::factory()->issue()->create(['github_issue_number' => 301]);
    TimeEntry::factory()->count(3)->create(['activity_id' => $orphan->id]);

    $response = SoloBoardServer::tool(DeleteIssueTool::class, [
        'issue_id' => $orphan->id,
    ]);

    $response->assertOk();

    $this->assertDatabaseMissing('activities', ['id' => $orphan->id]);
    $this->assertDatabaseMissing('time_entries', ['activity_id' => $orphan->id]);

    $this->assertDatabaseHas('activities', ['id' => $eligible->id]);
    expect(TimeEntry::query()->where('activity_id', $eligible->id)->count())->toBe(2);
});
