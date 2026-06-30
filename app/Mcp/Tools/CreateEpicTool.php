<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateEpicTool extends Tool
{
    protected string $name = 'create-epic';

    protected string $description = 'Creates a roadmap epic (type=Epic), the top of the roadmap tree. Epics mirror GitHub issues labelled type:prd. Optionally assign to a project by id and upsert by github_issue_number. Never accepts status — the epic status is manual (set via update-epic).';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'spec' => 'nullable|string',
            'project_id' => 'nullable|integer|exists:projects,id',
            'priority' => ['nullable', 'string', Rule::enum(ActivityPriority::class)],
            'due_date' => 'nullable|date',
            'github_issue_number' => 'nullable|integer',
            'github_synced_hash' => 'nullable|string',
        ], [
            'title.required' => 'You must provide a title for the epic.',
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'priority.Illuminate\Validation\Rules\Enum' => 'Invalid priority. Valid values: urgent, high, medium, low.',
        ]);

        $payload = [
            'type' => ActivityType::Epic,
            'title' => $validated['title'],
            'spec' => $validated['spec'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'priority' => $validated['priority'] ?? ActivityPriority::Medium,
            'due_date' => $validated['due_date'] ?? null,
        ];

        if (array_key_exists('github_synced_hash', $validated)) {
            $payload['github_synced_hash'] = $validated['github_synced_hash'];
        }

        if (! empty($validated['github_issue_number'])) {
            $epic = Activity::updateOrCreate(
                ['project_id' => $payload['project_id'], 'github_issue_number' => $validated['github_issue_number']],
                $payload,
            );

            if ($epic->wasRecentlyCreated && $epic->status === null) {
                $epic->update(['status' => ActivityStatus::Backlog]);
            }
        } else {
            $payload['status'] = ActivityStatus::Backlog;
            $epic = Activity::create($payload);
        }

        $epic->refresh();
        $epic->load('project');

        $data = [
            'id' => $epic->id,
            'title' => $epic->title,
            'slug' => $epic->slug,
            'status' => $epic->status->value,
            'priority' => $epic->priority->value,
            'progress' => $epic->progress,
            'project' => $epic->project?->name,
            'due_date' => $epic->due_date?->toDateString(),
            'github_issue_number' => $epic->github_issue_number,
            'github_synced_hash' => $epic->github_synced_hash,
            'created_at' => $epic->created_at->toDateTimeString(),
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
            'title' => $schema->string()->description('The title of the epic.')->required(),
            'spec' => $schema->string()->description('The epic specification in markdown format. Describe what this epic delivers, acceptance criteria, and any notes.'),
            'project_id' => $schema->integer()->description('Id of the project to assign the epic to. Use list-projects to find ids.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('Epic priority. Default: medium.'),
            'due_date' => $schema->string()->description('Due date in YYYY-MM-DD format.'),
            'github_issue_number' => $schema->integer()->description('GitHub issue number this epic mirrors. When provided, the epic is upserted by this number (no duplicate on repeated syncs). Never sets status.'),
            'github_synced_hash' => $schema->string()->description('Digest of the source GitHub issue (title + body + labels + state). Used to gate natural-language rewrites between syncs.'),
        ];
    }
}
