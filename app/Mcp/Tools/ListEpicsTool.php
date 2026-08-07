<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListEpicsTool extends Tool
{
    protected string $name = 'list-epics';

    protected string $description = 'Lists roadmap epics (type=Epic) with optional filtering by project id and status. Returns epic id, title, slug, status (manual), priority, progress percentage, issues count, total time, and GitHub mirror fields.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'status' => 'nullable|string|in:inbox,backlog,awaiting_approval,todo,doing,waiting,awaiting_validation,done',
            'limit' => 'nullable|integer|min:1|max:100',
        ], [
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'status.in' => 'Invalid status. Valid values: inbox, backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done.',
        ]);

        $query = Activity::query()->epics()->with(['project', 'children', 'timeEntries']);

        if (! empty($validated['project_id'])) {
            $query->forProject($validated['project_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $limit = $validated['limit'] ?? 20;

        $epics = $query->ordered()->limit($limit)->get();

        $data = $epics->map(fn (Activity $epic) => [
            'id' => $epic->id,
            'title' => $epic->title,
            'slug' => $epic->slug,
            'status' => $epic->status->value,
            'priority' => $epic->priority->value,
            'progress' => $epic->progress,
            'issues_count' => $epic->tasksCount(),
            'completed_issues' => $epic->completedTasksCount(),
            'total_time_minutes' => round($epic->total_time, 0),
            'project' => $epic->project?->name,
            'waiting_for' => $epic->waiting_for,
            'waiting_since' => $epic->waiting_since?->toDateTimeString(),
            'due_date' => $epic->due_date?->toDateString(),
            'is_running' => $epic->isRunning(),
            'github_issue_number' => $epic->github_issue_number,
            'github_synced_hash' => $epic->github_synced_hash,
        ])->all();

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Filter epics by project id. Optional. Use list-projects to find ids.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'awaiting_approval', 'todo', 'doing', 'waiting', 'awaiting_validation', 'done'])->description('Filter epics by status. Optional.'),
            'limit' => $schema->integer()->description('Maximum number of epics to return. Default: 20, max: 100.'),
        ];
    }
}
