<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateIssueTool extends Tool
{
    protected string $name = 'update-issue';

    protected string $description = 'Updates an existing roadmap issue (type=Issue). Accepts status, project_id and parent_id (the parent_id link is the second pass of the sync, where the tree is wired up). When status is changed to "done", the issue is marked done (stops running timers and sets completed_at). Provide only the fields you want to change.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'issue_id' => 'required|integer|exists:activities,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'nullable|integer|exists:projects,id',
            'parent_id' => 'nullable|integer|exists:activities,id',
            'status' => ['nullable', 'string', Rule::enum(ActivityStatus::class)],
            'priority' => ['nullable', 'string', Rule::enum(ActivityPriority::class)],
            'due_date' => 'nullable|date',
            'estimated_minutes' => 'nullable|integer|min:1',
            'github_issue_number' => 'nullable|integer',
            'github_synced_hash' => 'nullable|string',
        ], [
            'issue_id.required' => 'You must provide an issue_id. Use list-issues to find available issue ids.',
            'issue_id.exists' => 'Issue not found. Use list-issues to find available issue ids.',
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'parent_id.exists' => 'Parent not found. Use list-epics or list-issues to find available ids.',
        ]);

        $issue = Activity::query()->issues()->findOrFail($validated['issue_id']);

        if (isset($validated['status']) && $validated['status'] === ActivityStatus::Done->value && $issue->status !== ActivityStatus::Done) {
            $issue->markAsDone();
            $issue->refresh();
        }

        $updates = [];

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
        }

        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }

        if (array_key_exists('project_id', $validated)) {
            $updates['project_id'] = $validated['project_id'];
        }

        if (array_key_exists('parent_id', $validated)) {
            $updates['parent_id'] = $validated['parent_id'];
        }

        if (isset($validated['status']) && $validated['status'] !== ActivityStatus::Done->value) {
            $updates['status'] = $validated['status'];
        }

        if (isset($validated['priority'])) {
            $updates['priority'] = $validated['priority'];
        }

        if (isset($validated['due_date'])) {
            $updates['due_date'] = $validated['due_date'];
        }

        if (isset($validated['estimated_minutes'])) {
            $updates['estimated_minutes'] = $validated['estimated_minutes'];
        }

        if (isset($validated['github_issue_number'])) {
            $updates['github_issue_number'] = $validated['github_issue_number'];
        }

        if (array_key_exists('github_synced_hash', $validated)) {
            $updates['github_synced_hash'] = $validated['github_synced_hash'];
        }

        if (! empty($updates)) {
            $issue->update($updates);
        }

        $issue->load('project');

        $data = [
            'id' => $issue->id,
            'title' => $issue->title,
            'status' => $issue->status->value,
            'priority' => $issue->priority->value,
            'project' => $issue->project?->name,
            'parent_id' => $issue->parent_id,
            'due_date' => $issue->due_date?->toDateString(),
            'estimated_minutes' => $issue->estimated_minutes,
            'completed_at' => $issue->completed_at?->toDateTimeString(),
            'is_overdue' => $issue->isOverdue(),
            'is_running' => $issue->isRunning(),
            'github_issue_number' => $issue->github_issue_number,
            'github_synced_hash' => $issue->github_synced_hash,
            'updated_at' => $issue->updated_at->toDateTimeString(),
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
            'issue_id' => $schema->integer()->description('The id of the issue to update.')->required(),
            'title' => $schema->string()->description('New title for the issue.'),
            'description' => $schema->string()->description('New description in markdown format.'),
            'project_id' => $schema->integer()->description('Id of the project to assign the issue to.'),
            'parent_id' => $schema->integer()->description('Id of the parent activity (an Epic -> Fatia, or another Issue -> Follow-up). Set null for a loose issue (Avulsa).'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'todo', 'doing', 'done'])->description('New status. Setting to "done" will stop running timers and set completed_at.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('New priority.'),
            'due_date' => $schema->string()->description('New due date in YYYY-MM-DD format.'),
            'estimated_minutes' => $schema->integer()->description('New estimated time in minutes.'),
            'github_issue_number' => $schema->integer()->description('GitHub issue number this issue mirrors (upsert key).'),
            'github_synced_hash' => $schema->string()->description('Digest of the source GitHub issue, used to gate natural-language rewrites.'),
        ];
    }
}
