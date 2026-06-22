<?php

use App\Enums\FeaturePriority;
use App\Mcp\Prompts\FeaturePlanningPrompt;
use App\Mcp\Resources\ProjectOverviewResource;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\AddTaskToFeatureTool;
use App\Mcp\Tools\CreateFeatureTool;
use App\Mcp\Tools\DeleteFeatureTool;
use App\Mcp\Tools\GetFeatureTool;
use App\Mcp\Tools\ListFeaturesTool;
use App\Mcp\Tools\UpdateFeatureTool;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;

// ListFeaturesTool tests
test('list-features returns all features', function () {
    $feature1 = Feature::factory()->create(['title' => 'Feature Alpha']);
    $feature2 = Feature::factory()->create(['title' => 'Feature Beta']);
    $feature3 = Feature::factory()->create(['title' => 'Feature Gamma']);

    $response = SoloBoardServer::tool(ListFeaturesTool::class, []);

    $response->assertOk();
    $response->assertSee('Feature Alpha');
    $response->assertSee('Feature Beta');
    $response->assertSee('Feature Gamma');
});

test('list-features filters by project slug', function () {
    $project = Project::factory()->create();
    Feature::factory()->create(['project_id' => $project->id, 'title' => 'Project Feature']);
    Feature::factory()->create(['project_id' => null, 'title' => 'Unassigned Feature']);

    $response = SoloBoardServer::tool(ListFeaturesTool::class, [
        'project_slug' => $project->slug,
    ]);

    $response->assertOk();
    $response->assertSee('Project Feature');
    $response->assertDontSee('Unassigned Feature');
});

test('list-features filters by status', function () {
    // Status is now a persisted column, set explicitly
    Feature::factory()->create(['title' => 'Draft Feature', 'status' => 'draft']);
    Feature::factory()->create(['title' => 'Doing Feature', 'status' => 'doing']);
    Feature::factory()->create(['title' => 'Done Feature', 'status' => 'done']);

    $response = SoloBoardServer::tool(ListFeaturesTool::class, [
        'status' => 'doing',
    ]);

    $response->assertOk();
    $response->assertSee('Doing Feature');
    $response->assertDontSee('Draft Feature');
    $response->assertDontSee('Done Feature');
});

test('list-features returns progress and counts', function () {
    $feature = Feature::factory()->create(['title' => 'Progress Feature']);
    Task::factory()->count(3)->done()->create(['feature_id' => $feature->id]);
    Task::factory()->count(2)->todo()->create(['feature_id' => $feature->id]);

    $response = SoloBoardServer::tool(ListFeaturesTool::class, []);

    $response->assertOk();
    $response->assertSee('"tasks_count": 5');
    $response->assertSee('"completed_tasks": 3');
    $response->assertSee('"progress": 60');
});

test('list-features fails with invalid project slug', function () {
    $response = SoloBoardServer::tool(ListFeaturesTool::class, [
        'project_slug' => 'non-existent-project',
    ]);

    $response->assertHasErrors();
});

// GetFeatureTool tests
test('get-feature returns feature with full details', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->create([
        'project_id' => $project->id,
        'spec' => '## Feature Spec Content',
    ]);
    Task::factory()->count(2)->create(['feature_id' => $feature->id]);
    TimeEntry::factory()->create(['feature_id' => $feature->id, 'task_id' => null]);

    $response = SoloBoardServer::tool(GetFeatureTool::class, [
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();
    $response->assertSee($feature->title);
    $response->assertSee('## Feature Spec Content');
    $response->assertSee($project->name);
    $response->assertSee('"tasks":');
    $response->assertSee('"time_entries":');
});

test('get-feature fails for non-existent feature', function () {
    $response = SoloBoardServer::tool(GetFeatureTool::class, [
        'feature_id' => 999,
    ]);

    $response->assertHasErrors();
});

// CreateFeatureTool tests
test('create-feature creates a new feature', function () {
    $response = SoloBoardServer::tool(CreateFeatureTool::class, [
        'title' => 'User Authentication',
        'spec' => '## Overview\nImplement OAuth2 flow',
        'priority' => 'high',
    ]);

    $response->assertOk();
    $response->assertSee('User Authentication');
    $response->assertSee('"status": "draft"');

    $this->assertDatabaseHas('features', [
        'title' => 'User Authentication',
        'priority' => 'high',
    ]);
});

test('create-feature assigns to project by slug', function () {
    $project = Project::factory()->create();

    $response = SoloBoardServer::tool(CreateFeatureTool::class, [
        'title' => 'Dashboard Widgets',
        'project_slug' => $project->slug,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('features', [
        'title' => 'Dashboard Widgets',
        'project_id' => $project->id,
    ]);
});

test('create-feature uses default priority', function () {
    $response = SoloBoardServer::tool(CreateFeatureTool::class, [
        'title' => 'Simple Feature',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('features', [
        'title' => 'Simple Feature',
        'priority' => FeaturePriority::Medium->value,
    ]);
});

test('create-feature fails with invalid project slug', function () {
    $response = SoloBoardServer::tool(CreateFeatureTool::class, [
        'title' => 'Test Feature',
        'project_slug' => 'non-existent-project',
    ]);

    $response->assertHasErrors();
});

test('create-feature fails without title', function () {
    $response = SoloBoardServer::tool(CreateFeatureTool::class, []);

    $response->assertHasErrors();
});

// UpdateFeatureTool tests
test('update-feature updates title and spec', function () {
    $feature = Feature::factory()->create([
        'title' => 'Original Title',
        'spec' => 'Original spec',
    ]);

    $response = SoloBoardServer::tool(UpdateFeatureTool::class, [
        'feature_id' => $feature->id,
        'title' => 'Updated Title',
        'spec' => 'Updated spec',
    ]);

    $response->assertOk();
    $response->assertSee('Updated Title');

    $feature->refresh();
    expect($feature->title)->toBe('Updated Title');
    expect($feature->spec)->toBe('Updated spec');
});

test('update-feature changes project', function () {
    $oldProject = Project::factory()->create();
    $newProject = Project::factory()->create();
    $feature = Feature::factory()->create(['project_id' => $oldProject->id]);

    $response = SoloBoardServer::tool(UpdateFeatureTool::class, [
        'feature_id' => $feature->id,
        'project_slug' => $newProject->slug,
    ]);

    $response->assertOk();

    $feature->refresh();
    expect($feature->project_id)->toBe($newProject->id);
});

test('update-feature changes priority', function () {
    $feature = Feature::factory()->create(['priority' => FeaturePriority::Low]);

    $response = SoloBoardServer::tool(UpdateFeatureTool::class, [
        'feature_id' => $feature->id,
        'priority' => 'urgent',
    ]);

    $response->assertOk();

    $feature->refresh();
    expect($feature->priority)->toBe(FeaturePriority::Urgent);
});

test('update-feature persists status column', function () {
    $feature = Feature::factory()->create(['status' => 'backlog']);

    $response = SoloBoardServer::tool(UpdateFeatureTool::class, [
        'feature_id' => $feature->id,
        'status' => 'doing',
    ]);

    $response->assertOk();
    $response->assertSee('"status": "doing"');

    $this->assertDatabaseHas('features', [
        'id' => $feature->id,
        'status' => 'doing',
    ]);
});

test('update-feature rejects invalid status', function () {
    $feature = Feature::factory()->create();

    $response = SoloBoardServer::tool(UpdateFeatureTool::class, [
        'feature_id' => $feature->id,
        'status' => 'invalid-status',
    ]);

    $response->assertHasErrors();
});

test('update-feature fails for non-existent feature', function () {
    $response = SoloBoardServer::tool(UpdateFeatureTool::class, [
        'feature_id' => 999,
        'title' => 'Test',
    ]);

    $response->assertHasErrors();
});

// GitHub mirror tests (issue #85)
test('create-feature persists github_issue_number and github_synced_hash', function () {
    $response = SoloBoardServer::tool(CreateFeatureTool::class, [
        'title' => 'Mirrored Feature',
        'github_issue_number' => 42,
        'github_synced_hash' => 'hash-abc',
    ]);

    $response->assertOk();
    $response->assertSee('"github_issue_number": 42');

    $this->assertDatabaseHas('features', [
        'title' => 'Mirrored Feature',
        'github_issue_number' => 42,
        'github_synced_hash' => 'hash-abc',
    ]);
});

test('create-feature upserts by github_issue_number without duplicating', function () {
    SoloBoardServer::tool(CreateFeatureTool::class, [
        'title' => 'First Title',
        'github_issue_number' => 100,
        'github_synced_hash' => 'hash-1',
    ]);

    $response = SoloBoardServer::tool(CreateFeatureTool::class, [
        'title' => 'Rewritten Title',
        'github_issue_number' => 100,
        'github_synced_hash' => 'hash-2',
    ]);

    $response->assertOk();

    expect(Feature::query()->where('github_issue_number', 100)->count())->toBe(1);

    $this->assertDatabaseHas('features', [
        'github_issue_number' => 100,
        'title' => 'Rewritten Title',
        'github_synced_hash' => 'hash-2',
    ]);
});

test('update-feature persists github_issue_number and github_synced_hash', function () {
    $feature = Feature::factory()->create();

    $response = SoloBoardServer::tool(UpdateFeatureTool::class, [
        'feature_id' => $feature->id,
        'github_issue_number' => 7,
        'github_synced_hash' => 'hash-xyz',
    ]);

    $response->assertOk();
    $response->assertSee('"github_issue_number": 7');

    $this->assertDatabaseHas('features', [
        'id' => $feature->id,
        'github_issue_number' => 7,
        'github_synced_hash' => 'hash-xyz',
    ]);
});

test('mirroring a feature via update-feature without status does not change a defined status', function () {
    $feature = Feature::factory()->create([
        'status' => 'doing',
        'github_issue_number' => 55,
    ]);

    $response = SoloBoardServer::tool(UpdateFeatureTool::class, [
        'feature_id' => $feature->id,
        'title' => 'Rewritten plain title',
        'spec' => 'Rewritten plain spec',
        'github_synced_hash' => 'new-hash',
    ]);

    $response->assertOk();

    $feature->refresh();
    expect($feature->status->value)->toBe('doing');
    expect($feature->title)->toBe('Rewritten plain title');
});

test('list-features and get-feature expose github fields', function () {
    $feature = Feature::factory()->create([
        'github_issue_number' => 321,
        'github_synced_hash' => 'list-hash',
    ]);

    $list = SoloBoardServer::tool(ListFeaturesTool::class, []);
    $list->assertOk();
    $list->assertSee('"github_issue_number": 321');
    $list->assertSee('list-hash');

    $get = SoloBoardServer::tool(GetFeatureTool::class, ['feature_id' => $feature->id]);
    $get->assertOk();
    $get->assertSee('"github_issue_number": 321');
    $get->assertSee('list-hash');
});

// DeleteFeatureTool tests
test('delete-feature removes feature and unlinks tasks', function () {
    $feature = Feature::factory()->create();
    $tasks = Task::factory()->count(3)->create(['feature_id' => $feature->id]);
    TimeEntry::factory()->count(2)->create(['feature_id' => $feature->id, 'task_id' => null]);

    $response = SoloBoardServer::tool(DeleteFeatureTool::class, [
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();
    $response->assertSee('"deleted": true');
    $response->assertSee('"tasks_unlinked": 3');
    $response->assertSee('"time_entries_deleted": 2');

    $this->assertDatabaseMissing('features', ['id' => $feature->id]);
    $this->assertDatabaseMissing('time_entries', ['feature_id' => $feature->id]);

    // Tasks should still exist but with null feature_id
    foreach ($tasks as $task) {
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'feature_id' => null,
        ]);
    }
});

test('delete-feature fails for non-existent feature', function () {
    $response = SoloBoardServer::tool(DeleteFeatureTool::class, [
        'feature_id' => 999,
    ]);

    $response->assertHasErrors();
});

// AddTaskToFeatureTool tests
test('add-task-to-feature links task to feature', function () {
    $task = Task::factory()->create(['feature_id' => null]);
    $feature = Feature::factory()->create();

    $response = SoloBoardServer::tool(AddTaskToFeatureTool::class, [
        'task_id' => $task->id,
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();
    $response->assertSee('linked to feature');

    $task->refresh();
    expect($task->feature_id)->toBe($feature->id);
});

test('add-task-to-feature inherits project from feature', function () {
    $project = Project::factory()->create();
    $feature = Feature::factory()->create(['project_id' => $project->id]);
    $task = Task::factory()->create(['feature_id' => null, 'project_id' => null]);

    $response = SoloBoardServer::tool(AddTaskToFeatureTool::class, [
        'task_id' => $task->id,
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();

    $task->refresh();
    expect($task->feature_id)->toBe($feature->id);
    expect($task->project_id)->toBe($project->id);
});

test('add-task-to-feature does not override existing project', function () {
    $taskProject = Project::factory()->create();
    $featureProject = Project::factory()->create();
    $feature = Feature::factory()->create(['project_id' => $featureProject->id]);
    $task = Task::factory()->create(['feature_id' => null, 'project_id' => $taskProject->id]);

    $response = SoloBoardServer::tool(AddTaskToFeatureTool::class, [
        'task_id' => $task->id,
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();

    $task->refresh();
    expect($task->feature_id)->toBe($feature->id);
    expect($task->project_id)->toBe($taskProject->id);
});

test('add-task-to-feature fails for non-existent task', function () {
    $feature = Feature::factory()->create();

    $response = SoloBoardServer::tool(AddTaskToFeatureTool::class, [
        'task_id' => 999,
        'feature_id' => $feature->id,
    ]);

    $response->assertHasErrors();
});

test('add-task-to-feature fails for non-existent feature', function () {
    $task = Task::factory()->create();

    $response = SoloBoardServer::tool(AddTaskToFeatureTool::class, [
        'task_id' => $task->id,
        'feature_id' => 999,
    ]);

    $response->assertHasErrors();
});

// ProjectOverviewResource tests (features section)
test('overview resource includes active features', function () {
    // Status is now a persisted column, set explicitly
    Feature::factory()->create(['title' => 'Active Feature', 'status' => 'doing']);
    Feature::factory()->create(['title' => 'Completed Feature', 'status' => 'done']);

    $response = SoloBoardServer::resource(ProjectOverviewResource::class);

    $response->assertOk();
    $response->assertSee('"active_features":');
    // Only non-done features should be included
    $response->assertSee('Active Feature');
    $response->assertDontSee('Completed Feature');
});

// FeaturePlanningPrompt tests
test('feature-planning prompt returns planning context', function () {
    $project = Project::factory()->create(['description' => 'Project description']);
    $feature = Feature::factory()->create([
        'project_id' => $project->id,
        'spec' => '## Feature Spec\n- Item 1\n- Item 2',
    ]);
    Task::factory()->doing()->create(['feature_id' => $feature->id, 'title' => 'Task In Progress']);
    Task::factory()->done()->create(['feature_id' => $feature->id, 'title' => 'Completed Task']);

    $response = SoloBoardServer::prompt(FeaturePlanningPrompt::class, [
        'feature_id' => $feature->id,
    ]);

    $response->assertOk();
    $response->assertSee($feature->title);
    $response->assertSee('## Feature Specification');
    $response->assertSee($project->name);
    $response->assertSee('Task In Progress');
    $response->assertSee('Completed Task');
});

test('feature-planning prompt fails for non-existent feature', function () {
    SoloBoardServer::prompt(FeaturePlanningPrompt::class, [
        'feature_id' => 999,
    ]);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
