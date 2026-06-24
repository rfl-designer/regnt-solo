<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListFeaturesTool extends Tool
{
    protected string $name = 'list-features';

    protected string $description = 'Lists features with optional filtering by project slug and status. Returns feature id, title, slug, status (computed from tasks), priority, progress percentage, tasks count, and total time.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_slug' => 'nullable|string|exists:projects,slug',
            'status' => 'nullable|string|in:backlog,todo,doing,done',
            'limit' => 'nullable|integer|min:1|max:100',
        ], [
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'status.in' => 'Invalid status. Valid values: backlog, todo, doing, done.',
        ]);

        $query = Activity::query()->epics()->with(['project', 'children', 'timeEntries']);

        if (! empty($validated['project_slug'])) {
            $project = Project::query()->where('slug', $validated['project_slug'])->first();
            $query->forProject($project->id);
        }

        $limit = $validated['limit'] ?? 20;

        $features = $query->ordered()->limit($limit)->get();

        // Filter by computed status if provided
        if (! empty($validated['status'])) {
            $features = $features->filter(fn (Activity $f) => $f->status->value === $validated['status'])->values();
        }

        $data = $features->map(fn (Activity $feature) => [
            'id' => $feature->id,
            'title' => $feature->title,
            'slug' => $feature->slug,
            'status' => $feature->status->value,
            'priority' => $feature->priority->value,
            'progress' => $feature->progress,
            'tasks_count' => $feature->tasksCount(),
            'completed_tasks' => $feature->completedTasksCount(),
            'total_time_minutes' => round($feature->total_time, 0),
            'project' => $feature->project?->name,
            'due_date' => $feature->due_date?->toDateString(),
            'is_running' => $feature->isRunning(),
            'github_issue_number' => $feature->github_issue_number,
            'github_synced_hash' => $feature->github_synced_hash,
        ])->all();

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
            'project_slug' => $schema->string()->description('Filter features by project slug. Optional.'),
            'status' => $schema->string()->enum(['backlog', 'todo', 'doing', 'done'])->description('Filter features by computed status. Optional.'),
            'limit' => $schema->integer()->description('Maximum number of features to return. Default: 20, max: 100.'),
        ];
    }
}
