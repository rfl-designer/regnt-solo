<?php

use App\Enums\StakeholderIssueStatus;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Mcp\Prompts\StakeholderIssuePlanningPrompt;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\ListStakeholderIssuesTool;
use App\Mcp\Tools\PromoteStakeholderIssueToFeatureTool;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\StakeholderIssue;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('list-stakeholder-issues returns issues and supports filters', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    $stakeholderA = Stakeholder::factory()->create(['project_id' => $projectA->id]);
    $stakeholderB = Stakeholder::factory()->create(['project_id' => $projectB->id]);

    StakeholderIssue::factory()->toFeature()->create([
        'project_id' => $projectA->id,
        'stakeholder_id' => $stakeholderA->id,
        'comment' => 'Request CSV export in dashboard.',
    ]);

    StakeholderIssue::factory()->create([
        'project_id' => $projectA->id,
        'stakeholder_id' => $stakeholderA->id,
        'comment' => 'Adjust header text.',
        'status' => StakeholderIssueStatus::Unread,
    ]);

    StakeholderIssue::factory()->toFeature()->create([
        'project_id' => $projectB->id,
        'stakeholder_id' => $stakeholderB->id,
        'comment' => 'Add more search filters.',
    ]);

    $response = SoloBoardServer::tool(ListStakeholderIssuesTool::class, [
        'project_slug' => $projectA->slug,
        'status' => StakeholderIssueStatus::ToFeature->value,
    ]);

    $response->assertOk();
    $response->assertSee('Request CSV export in dashboard.');
    $response->assertDontSee('Adjust header text.');
    $response->assertDontSee('Add more search filters.');
});

test('promote-stakeholder-issue creates feature and links issue', function () {
    $project = Project::factory()->create();
    $stakeholder = Stakeholder::factory()->create(['project_id' => $project->id]);

    $issue = StakeholderIssue::factory()->toFeature()->create([
        'project_id' => $project->id,
        'stakeholder_id' => $stakeholder->id,
        'comment' => 'Need a weekly stakeholder report by period.',
    ]);

    $response = SoloBoardServer::tool(PromoteStakeholderIssueToFeatureTool::class, [
        'issue_id' => $issue->id,
        'title' => 'Stakeholder report by period',
        'service_class' => 'emergency',
        'emergency_reason' => 'Stakeholder bloqueado',
    ]);

    $response->assertOk();
    $response->assertSee('"created_feature": true');
    $response->assertSee('Stakeholder report by period');

    $issue->refresh();

    expect($issue->activity_id)->not->toBeNull();
    expect($issue->status)->toBe(StakeholderIssueStatus::Feature);
    expect($issue->converted_at)->not->toBeNull();

    $this->assertDatabaseHas('activities', [
        'id' => $issue->activity_id,
        'project_id' => $project->id,
        'title' => 'Stakeholder report by period',
        'service_class' => 'emergency',
    ]);
});

test('promote-stakeholder-issue no longer accepts a priority field', function () {
    $issue = StakeholderIssue::factory()->toFeature()->create();

    $response = SoloBoardServer::tool(PromoteStakeholderIssueToFeatureTool::class, [
        'issue_id' => $issue->id,
        'title' => 'Priority-less feature',
        'priority' => 'urgent',
    ]);

    $response->assertOk();

    $issue->refresh();

    // The unvalidated `priority` field is silently ignored; the created
    // activity gets the database default, not the legacy input.
    $this->assertDatabaseHas('activities', [
        'id' => $issue->activity_id,
        'priority' => 'medium',
    ]);
});

test('promote-stakeholder-issue defaults service_class to standard and accepts an explicit value', function () {
    $issue = StakeholderIssue::factory()->toFeature()->create();

    $response = SoloBoardServer::tool(PromoteStakeholderIssueToFeatureTool::class, [
        'issue_id' => $issue->id,
        'title' => 'Default service class feature',
    ]);

    $response->assertOk();
    $response->assertSee('"feature_service_class": "standard"');

    $issue2 = StakeholderIssue::factory()->toFeature()->create();

    $response2 = SoloBoardServer::tool(PromoteStakeholderIssueToFeatureTool::class, [
        'issue_id' => $issue2->id,
        'title' => 'Emergency feature',
        'service_class' => 'emergency',
        'emergency_reason' => 'Stakeholder bloqueado',
    ]);

    $response2->assertOk();
    $response2->assertSee('"feature_service_class": "emergency"');

    $issue2->refresh();

    $this->assertDatabaseHas('activities', [
        'id' => $issue2->activity_id,
        'service_class' => 'emergency',
    ]);
});

test('promote-stakeholder-issue refuses fixed_date without a due date', function () {
    $issue = StakeholderIssue::factory()->toFeature()->create();

    $response = SoloBoardServer::tool(PromoteStakeholderIssueToFeatureTool::class, [
        'issue_id' => $issue->id,
        'title' => 'Fixed date feature',
        'service_class' => 'fixed_date',
    ]);

    $response->assertHasErrors([FixedDateRequiresDueDateException::MESSAGE]);

    $issue->refresh();
    expect($issue->activity_id)->toBeNull();
});

test('promote-stakeholder-issue is idempotent when issue already has feature', function () {
    $project = Project::factory()->create();
    $stakeholder = Stakeholder::factory()->create(['project_id' => $project->id]);
    $feature = Activity::factory()->epic()->create([
        'project_id' => $project->id,
        'title' => 'Feature already created',
    ]);

    $issue = StakeholderIssue::factory()->featured()->create([
        'project_id' => $project->id,
        'stakeholder_id' => $stakeholder->id,
        'activity_id' => $feature->id,
    ]);

    $existingFeaturesCount = Activity::query()->epics()->count();

    $response = SoloBoardServer::tool(PromoteStakeholderIssueToFeatureTool::class, [
        'issue_id' => $issue->id,
    ]);

    $response->assertOk();
    $response->assertSee('"created_feature": false');
    $response->assertSee('Feature already created');

    expect(Activity::query()->epics()->count())->toBe($existingFeaturesCount);
    expect($issue->fresh()->activity_id)->toBe($feature->id);
});

test('stakeholder issue planning prompt returns issue context', function () {
    $project = Project::factory()->create();
    $stakeholder = Stakeholder::factory()->create(['project_id' => $project->id]);

    $issue = StakeholderIssue::factory()->toFeature()->create([
        'project_id' => $project->id,
        'stakeholder_id' => $stakeholder->id,
        'comment' => 'Gostaria de acompanhar métricas semanais por canal.',
    ]);

    $response = SoloBoardServer::prompt(StakeholderIssuePlanningPrompt::class, [
        'issue_id' => (string) $issue->id,
    ]);

    $response->assertOk();
    $response->assertSee('Stakeholder Issue');
    $response->assertSee('Gostaria de acompanhar métricas semanais por canal.');
    $response->assertSee($project->name);
    $response->assertSee('promote-stakeholder-issue');
});

test('stakeholder issue planning prompt fails for non-existent issue', function () {
    SoloBoardServer::prompt(StakeholderIssuePlanningPrompt::class, [
        'issue_id' => '99999',
    ]);
})->throws(ModelNotFoundException::class);
