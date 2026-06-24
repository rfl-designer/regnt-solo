<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetFeatureTool extends Tool
{
    protected string $name = 'get-feature';

    protected string $description = 'Gets a single feature by ID with full details including spec, tasks, time entries, status, and progress.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'feature_id' => 'required|integer|exists:activities,id',
        ], [
            'feature_id.required' => 'You must provide a feature_id. Use list-features to find available feature IDs.',
            'feature_id.exists' => 'Feature not found. Use list-features to find available feature IDs.',
        ]);

        $feature = Activity::query()->epics()
            ->with(['project', 'children.project', 'timeEntries'])
            ->findOrFail($validated['feature_id']);

        $data = [
            'id' => $feature->id,
            'title' => $feature->title,
            'slug' => $feature->slug,
            'spec' => $feature->spec,
            'status' => $feature->status->value,
            'priority' => $feature->priority->value,
            'progress' => $feature->progress,
            'project' => $feature->project ? [
                'id' => $feature->project->id,
                'name' => $feature->project->name,
                'slug' => $feature->project->slug,
            ] : null,
            'due_date' => $feature->due_date?->toDateString(),
            'is_running' => $feature->isRunning(),
            'tasks' => $feature->children->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'estimated_minutes' => $task->estimated_minutes,
                'is_running' => $task->isRunning(),
            ])->all(),
            'tasks_count' => $feature->tasksCount(),
            'completed_tasks' => $feature->completedTasksCount(),
            'time_entries' => $feature->timeEntries->map(fn ($entry) => [
                'id' => $entry->id,
                'started_at' => $entry->started_at->toDateTimeString(),
                'stopped_at' => $entry->stopped_at?->toDateTimeString(),
                'duration_minutes' => $entry->duration_minutes,
                'notes' => $entry->notes,
                'is_focus_session' => $entry->is_focus_session,
            ])->all(),
            'total_time_minutes' => round($feature->total_time, 0),
            'github_issue_number' => $feature->github_issue_number,
            'github_synced_hash' => $feature->github_synced_hash,
            'created_at' => $feature->created_at->toDateTimeString(),
            'updated_at' => $feature->updated_at->toDateTimeString(),
        ];

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'feature_id' => $schema->integer()->description('The ID of the feature to retrieve.')->required(),
        ];
    }
}
