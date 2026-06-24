<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unify Feature and Task into a single Activity model (table `activities`).
     *
     * Strategy: rename `tasks` -> `activities` (preserves ids and child FKs),
     * fold the existing features in as Epics, then re-point the related tables
     * to `activity_id`. Discriminated by `github_issue_number`.
     */
    public function up(): void
    {
        Schema::rename('tasks', 'activities');

        Schema::table('activities', function (Blueprint $table): void {
            $table->dropForeign(['feature_id']);
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->renameColumn('feature_id', 'parent_id');
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->string('type')->default('task')->after('id');
            $table->string('slug')->nullable()->after('title');
            $table->text('spec')->nullable()->after('description');
            $table->string('status')->nullable()->change();
        });

        DB::table('activities')->whereNotNull('github_issue_number')->update(['type' => 'issue']);
        DB::table('activities')->whereNull('github_issue_number')->update(['type' => 'task']);

        $featureIdMap = [];

        foreach (DB::table('features')->get() as $feature) {
            $featureIdMap[$feature->id] = DB::table('activities')->insertGetId([
                'type' => 'epic',
                'project_id' => $feature->project_id,
                'parent_id' => null,
                'title' => $feature->title,
                'slug' => $feature->slug,
                'description' => null,
                'spec' => $feature->spec,
                'status' => $feature->status === 'draft' ? 'backlog' : $feature->status,
                'priority' => $feature->priority,
                'due_date' => $feature->due_date,
                'sort_order' => $feature->sort_order,
                'github_issue_number' => $feature->github_issue_number,
                'github_synced_hash' => $feature->github_synced_hash,
                'created_at' => $feature->created_at,
                'updated_at' => $feature->updated_at,
            ]);
        }

        foreach ($featureIdMap as $oldFeatureId => $newActivityId) {
            DB::table('activities')->where('parent_id', $oldFeatureId)->update(['parent_id' => $newActivityId]);
        }

        Schema::table('activities', function (Blueprint $table): void {
            $table->index('type');
            $table->foreign('parent_id')->references('id')->on('activities')->nullOnDelete();
        });

        Schema::table('time_entries', function (Blueprint $table): void {
            $table->foreignId('activity_id')->nullable()->after('id')->index()->constrained()->nullOnDelete();
        });

        DB::statement('UPDATE time_entries SET activity_id = task_id WHERE task_id IS NOT NULL');

        foreach ($featureIdMap as $oldFeatureId => $newActivityId) {
            DB::table('time_entries')->where('feature_id', $oldFeatureId)->update(['activity_id' => $newActivityId]);
        }

        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropForeign(['task_id']);
            $table->dropForeign(['feature_id']);
            $table->dropIndex('time_entries_task_id_started_at_index');
            $table->dropIndex('time_entries_feature_id_index');
        });

        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropColumn(['task_id', 'feature_id']);
        });

        Schema::table('time_entries', function (Blueprint $table): void {
            $table->index(['activity_id', 'started_at']);
        });

        Schema::table('stakeholder_issues', function (Blueprint $table): void {
            $table->foreignId('activity_id')->nullable()->after('project_id')->index()->constrained()->nullOnDelete();
        });

        foreach ($featureIdMap as $oldFeatureId => $newActivityId) {
            DB::table('stakeholder_issues')->where('feature_id', $oldFeatureId)->update(['activity_id' => $newActivityId]);
        }

        Schema::table('stakeholder_issues', function (Blueprint $table): void {
            $table->dropForeign(['feature_id']);
            $table->dropIndex('stakeholder_issues_feature_id_index');
            $table->dropColumn('feature_id');
        });

        Schema::rename('task_status_changes', 'activity_status_changes');
        Schema::table('activity_status_changes', function (Blueprint $table): void {
            $table->renameColumn('task_id', 'activity_id');
        });

        Schema::rename('task_commits', 'activity_commits');
        Schema::table('activity_commits', function (Blueprint $table): void {
            $table->renameColumn('task_id', 'activity_id');
        });

        Schema::rename('daily_plan_task', 'daily_plan_activity');
        Schema::table('daily_plan_activity', function (Blueprint $table): void {
            $table->renameColumn('task_id', 'activity_id');
        });

        Schema::drop('features');
    }

    /**
     * Reverse the migration: split `activities` back into `tasks` and `features`.
     */
    public function down(): void
    {
        Schema::create('features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('spec')->nullable();
            $table->string('priority')->default('medium');
            $table->string('status')->default('draft');
            $table->date('due_date')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('github_issue_number')->nullable()->unique();
            $table->string('github_synced_hash')->nullable();
            $table->timestamps();
        });

        $activityToFeatureMap = [];

        foreach (DB::table('activities')->where('type', 'epic')->get() as $epic) {
            $activityToFeatureMap[$epic->id] = DB::table('features')->insertGetId([
                'project_id' => $epic->project_id,
                'title' => $epic->title,
                'slug' => $epic->slug,
                'spec' => $epic->spec,
                'priority' => $epic->priority,
                'status' => $epic->status ?? 'backlog',
                'due_date' => $epic->due_date,
                'sort_order' => $epic->sort_order,
                'github_issue_number' => $epic->github_issue_number,
                'github_synced_hash' => $epic->github_synced_hash,
                'created_at' => $epic->created_at,
                'updated_at' => $epic->updated_at,
            ]);
        }

        Schema::rename('daily_plan_activity', 'daily_plan_task');
        Schema::table('daily_plan_task', function (Blueprint $table): void {
            $table->renameColumn('activity_id', 'task_id');
        });

        Schema::rename('activity_commits', 'task_commits');
        Schema::table('task_commits', function (Blueprint $table): void {
            $table->renameColumn('activity_id', 'task_id');
        });

        Schema::rename('activity_status_changes', 'task_status_changes');
        Schema::table('task_status_changes', function (Blueprint $table): void {
            $table->renameColumn('activity_id', 'task_id');
        });

        Schema::table('stakeholder_issues', function (Blueprint $table): void {
            $table->foreignId('feature_id')->nullable()->after('project_id')->index()->constrained()->nullOnDelete();
        });

        foreach ($activityToFeatureMap as $activityId => $featureId) {
            DB::table('stakeholder_issues')->where('activity_id', $activityId)->update(['feature_id' => $featureId]);
        }

        Schema::table('stakeholder_issues', function (Blueprint $table): void {
            $table->dropForeign(['activity_id']);
            $table->dropIndex('stakeholder_issues_activity_id_index');
            $table->dropColumn('activity_id');
        });

        Schema::table('time_entries', function (Blueprint $table): void {
            $table->foreignId('feature_id')->nullable()->after('id')->index()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->after('id')->index();
        });

        DB::statement('UPDATE time_entries SET task_id = activity_id WHERE activity_id IS NOT NULL');

        foreach ($activityToFeatureMap as $activityId => $featureId) {
            DB::table('time_entries')->where('activity_id', $activityId)->update([
                'task_id' => null,
                'feature_id' => $featureId,
            ]);
        }

        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropForeign(['activity_id']);
            $table->dropIndex('time_entries_activity_id_index');
            $table->dropIndex('time_entries_activity_id_started_at_index');
        });

        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropColumn('activity_id');
            $table->foreign('task_id')->references('id')->on('activities')->nullOnDelete();
            $table->index(['task_id', 'started_at']);
        });

        DB::table('activities')->where('type', 'epic')->delete();

        Schema::table('activities', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'slug', 'spec']);
            $table->renameColumn('parent_id', 'feature_id');
        });

        Schema::table('activities', function (Blueprint $table): void {
            $table->foreign('feature_id')->references('id')->on('features')->nullOnDelete();
        });

        Schema::rename('activities', 'tasks');

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('status')->default('inbox')->nullable(false)->change();
        });
    }
};
