<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Reconstruct the legacy schema (features + tasks) by reversing the unify
 * migration, then run it forward over hand-built legacy data so we can assert
 * the resulting `activities` rows and that personal items are never touched.
 */
function unifyMigration(): object
{
    return require database_path('migrations/2026_06_24_191325_unify_features_and_tasks_into_activities.php');
}

it('converts features to epics and tasks to issues/tasks, leaving personal items untouched', function (): void {
    $migration = unifyMigration();
    $migration->down();

    $projectId = DB::table('projects')->insertGetId([
        'name' => 'Roadmap Project',
        'slug' => 'roadmap-project',
        'status' => 'active',
        'priority' => 'medium',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Legacy feature (always becomes an Epic). Draft status must map to Backlog.
    $featureId = DB::table('features')->insertGetId([
        'project_id' => $projectId,
        'title' => 'Authentication',
        'slug' => 'authentication',
        'spec' => '## Spec',
        'priority' => 'high',
        'status' => 'draft',
        'sort_order' => 0,
        'github_issue_number' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Roadmap task: has a github issue number and hangs off the feature -> Issue (Fatia).
    DB::table('tasks')->insert([
        'project_id' => $projectId,
        'feature_id' => $featureId,
        'title' => 'Login screen',
        'status' => 'todo',
        'priority' => 'medium',
        'sort_order' => 0,
        'github_issue_number' => 11,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Loose roadmap task: github number but no parent -> Issue (Avulsa).
    DB::table('tasks')->insert([
        'project_id' => $projectId,
        'feature_id' => null,
        'title' => 'Loose issue',
        'status' => 'backlog',
        'priority' => 'low',
        'sort_order' => 1,
        'github_issue_number' => 12,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Personal task: no github number -> Task. Must stay exactly as it was.
    DB::table('tasks')->insert([
        'project_id' => null,
        'feature_id' => null,
        'title' => 'Cobrar manual de marca',
        'description' => 'Recado pessoal',
        'status' => 'inbox',
        'priority' => 'medium',
        'sort_order' => 2,
        'github_issue_number' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    // Feature -> Epic, draft status mapped to backlog.
    $epic = DB::table('activities')->where('github_issue_number', 10)->first();
    expect($epic->type)->toBe('epic')
        ->and($epic->status)->toBe('backlog')
        ->and($epic->parent_id)->toBeNull()
        ->and($epic->slug)->toBe('authentication');

    // Roadmap task with a parent feature -> Issue, parent_id resolved to the epic.
    $issue = DB::table('activities')->where('github_issue_number', 11)->first();
    expect($issue->type)->toBe('issue')
        ->and($issue->parent_id)->toBe($epic->id);

    // Loose roadmap task -> Issue with no parent.
    $loose = DB::table('activities')->where('github_issue_number', 12)->first();
    expect($loose->type)->toBe('issue')
        ->and($loose->parent_id)->toBeNull();

    // Personal task -> Task, untouched, no parent and no github number.
    $personal = DB::table('activities')->where('title', 'Cobrar manual de marca')->first();
    expect($personal->type)->toBe('task')
        ->and($personal->status)->toBe('inbox')
        ->and($personal->parent_id)->toBeNull()
        ->and($personal->github_issue_number)->toBeNull()
        ->and($personal->description)->toBe('Recado pessoal');

    // Legacy tables are gone; everything lives in `activities`.
    expect(DB::getSchemaBuilder()->hasTable('features'))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasTable('tasks'))->toBeFalse()
        ->and(DB::table('activities')->count())->toBe(4);
});

it('keeps the original task id when a personal task is migrated', function (): void {
    $migration = unifyMigration();
    $migration->down();

    $taskId = DB::table('tasks')->insertGetId([
        'title' => 'Mandar email pro designer',
        'status' => 'inbox',
        'priority' => 'medium',
        'sort_order' => 0,
        'github_issue_number' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    // The rename preserves task ids, so the personal task keeps its primary key.
    $activity = DB::table('activities')->where('title', 'Mandar email pro designer')->first();
    expect($activity->id)->toBe($taskId)
        ->and($activity->type)->toBe('task');
});
