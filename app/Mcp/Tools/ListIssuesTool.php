<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListIssuesTool extends Tool
{
    protected string $name = 'list-issues';

    protected string $description = 'Lists roadmap issues (type=Issue) with optional filtering by project id, status, and limit. Returns issue id, title, status, priority, project, parent_id, due_date, and GitHub mirror fields. Used by the sync to reconcile mirrored issues by github_issue_number.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
            'status' => 'nullable|string|in:inbox,backlog,todo,doing,done',
            'limit' => 'nullable|integer|min:1|max:100',
        ], [
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'status.in' => 'Invalid status. Valid values: inbox, backlog, todo, doing, done.',
        ]);

        $query = Activity::query()->issues()->with('project');

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['status'])) {
            $query->byStatus(ActivityStatus::from($validated['status']));
        }

        $limit = $validated['limit'] ?? 20;

        $issues = $query->latest()->limit($limit)->get();

        $data = $issues->map(fn (Activity $issue) => [
            'id' => $issue->id,
            'title' => $issue->title,
            'status' => $issue->status->value,
            'priority' => $issue->priority->value,
            'project' => $issue->project?->name,
            'parent_id' => $issue->parent_id,
            'due_date' => $issue->due_date?->toDateString(),
            'is_overdue' => $issue->isOverdue(),
            'is_running' => $issue->isRunning(),
            'github_issue_number' => $issue->github_issue_number,
            'github_synced_hash' => $issue->github_synced_hash,
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
            'project_id' => $schema->integer()->description('Filter issues by project id. Optional. Use list-projects to find ids.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'todo', 'doing', 'done'])->description('Filter issues by status. Optional.'),
            'limit' => $schema->integer()->description('Maximum number of issues to return. Default: 20, max: 100.'),
        ];
    }
}
