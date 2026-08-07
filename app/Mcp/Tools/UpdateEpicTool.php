<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Exceptions\WaitingRequiresWaitingForException;
use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateEpicTool extends Tool
{
    protected string $name = 'update-epic';

    protected string $description = 'Updates an existing roadmap epic (type=Epic). The epic status is manual (ADR 0002) and may be changed here. Setting status to awaiting_approval, waiting or awaiting_validation requires waiting_for — client-side waits auto-fill it from the effective client when omitted, waiting (internal) has no default and is refused without one. Provide only the fields you want to change.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'epic_id' => 'required|integer|exists:activities,id',
            'title' => 'nullable|string|max:255',
            'spec' => 'nullable|string',
            'project_id' => 'nullable|integer|exists:projects,id',
            'priority' => ['nullable', 'string', Rule::enum(ActivityPriority::class)],
            'status' => ['nullable', 'string', Rule::enum(ActivityStatus::class)],
            'waiting_for' => 'nullable|string|max:255',
            'due_date' => 'nullable|date',
            'github_issue_number' => 'nullable|integer',
            'github_synced_hash' => 'nullable|string',
        ], [
            'epic_id.required' => 'You must provide an epic_id. Use list-epics to find available epic ids.',
            'epic_id.exists' => 'Epic not found. Use list-epics to find available epic ids.',
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'priority.Illuminate\Validation\Rules\Enum' => 'Invalid priority. Valid values: urgent, high, medium, low.',
            'status.Illuminate\Validation\Rules\Enum' => 'Invalid status. Valid values: inbox, backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done.',
        ]);

        $epic = Activity::query()->epics()->findOrFail($validated['epic_id']);

        $updates = [];

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
        }

        if (array_key_exists('spec', $validated)) {
            $updates['spec'] = $validated['spec'];
        }

        if (array_key_exists('project_id', $validated)) {
            $updates['project_id'] = $validated['project_id'];
        }

        if (isset($validated['priority'])) {
            $updates['priority'] = $validated['priority'];
        }

        if (isset($validated['status'])) {
            $updates['status'] = $validated['status'];
        }

        if (array_key_exists('waiting_for', $validated)) {
            $updates['waiting_for'] = $validated['waiting_for'];
        }

        if (array_key_exists('due_date', $validated)) {
            $updates['due_date'] = $validated['due_date'];
        }

        if (isset($validated['github_issue_number'])) {
            $updates['github_issue_number'] = $validated['github_issue_number'];
        }

        if (array_key_exists('github_synced_hash', $validated)) {
            $updates['github_synced_hash'] = $validated['github_synced_hash'];
        }

        if (! empty($updates)) {
            try {
                $epic->update($updates);
            } catch (WaitingRequiresWaitingForException $e) {
                return Response::error($e->getMessage());
            }
        }

        $epic->load(['project', 'children']);

        $data = [
            'id' => $epic->id,
            'title' => $epic->title,
            'slug' => $epic->slug,
            'status' => $epic->status->value,
            'priority' => $epic->priority->value,
            'progress' => $epic->progress,
            'project' => $epic->project?->name,
            'waiting_for' => $epic->waiting_for,
            'waiting_since' => $epic->waiting_since?->toDateTimeString(),
            'due_date' => $epic->due_date?->toDateString(),
            'issues_count' => $epic->tasksCount(),
            'is_running' => $epic->isRunning(),
            'github_issue_number' => $epic->github_issue_number,
            'github_synced_hash' => $epic->github_synced_hash,
            'updated_at' => $epic->updated_at->toDateTimeString(),
        ];

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
            'epic_id' => $schema->integer()->description('The id of the epic to update.')->required(),
            'title' => $schema->string()->description('New title for the epic.'),
            'spec' => $schema->string()->description('New specification in markdown format.'),
            'project_id' => $schema->integer()->description('Id of the project to assign the epic to.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('New priority.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'awaiting_approval', 'todo', 'doing', 'waiting', 'awaiting_validation', 'done'])->description('Epic status (manual, ADR 0002). The sync never sets this; only manual edits do.'),
            'waiting_for' => $schema->string()->description('Who the epic is waiting on ("esperando quem"). Required when status is awaiting_approval, waiting or awaiting_validation — client-side waits auto-fill this from the effective client when omitted; waiting (internal) has no default and is refused without an explicit value.'),
            'due_date' => $schema->string()->description('New due date in YYYY-MM-DD format.'),
            'github_issue_number' => $schema->integer()->description('GitHub issue number this epic mirrors (upsert key).'),
            'github_synced_hash' => $schema->string()->description('Digest of the source GitHub issue, used to gate natural-language rewrites.'),
        ];
    }
}
