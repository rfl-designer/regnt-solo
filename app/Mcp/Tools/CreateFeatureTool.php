<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateFeatureTool extends Tool
{
    protected string $name = 'create-feature';

    protected string $description = 'Creates a new feature. Features group related tasks and track progress. Optionally assign to a project by slug.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'spec' => 'nullable|string',
            'project_slug' => 'nullable|string|exists:projects,slug',
            'priority' => ['nullable', 'string', Rule::enum(ActivityPriority::class)],
            'due_date' => 'nullable|date',
            'github_issue_number' => 'nullable|integer',
            'github_synced_hash' => 'nullable|string',
        ], [
            'title.required' => 'You must provide a title for the feature.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'priority.Illuminate\Validation\Rules\Enum' => 'Invalid priority. Valid values: urgent, high, medium, low.',
        ]);

        $projectId = null;
        if (! empty($validated['project_slug'])) {
            $projectId = Project::query()->where('slug', $validated['project_slug'])->value('id');
        }

        $payload = [
            'type' => ActivityType::Epic,
            'title' => $validated['title'],
            'spec' => $validated['spec'] ?? null,
            'project_id' => $projectId,
            'priority' => $validated['priority'] ?? ActivityPriority::Medium,
            'due_date' => $validated['due_date'] ?? null,
        ];

        if (array_key_exists('github_synced_hash', $validated)) {
            $payload['github_synced_hash'] = $validated['github_synced_hash'];
        }

        if (! empty($validated['github_issue_number'])) {
            $feature = Activity::updateOrCreate(
                ['github_issue_number' => $validated['github_issue_number']],
                $payload,
            );

            if ($feature->wasRecentlyCreated && $feature->status === null) {
                $feature->update(['status' => ActivityStatus::Backlog]);
            }
        } else {
            $payload['status'] = ActivityStatus::Backlog;
            $feature = Activity::create($payload);
        }

        $feature->refresh();
        $feature->load('project');

        $data = [
            'id' => $feature->id,
            'title' => $feature->title,
            'slug' => $feature->slug,
            'status' => $feature->status->value,
            'priority' => $feature->priority->value,
            'progress' => $feature->progress,
            'project' => $feature->project?->name,
            'due_date' => $feature->due_date?->toDateString(),
            'github_issue_number' => $feature->github_issue_number,
            'github_synced_hash' => $feature->github_synced_hash,
            'created_at' => $feature->created_at->toDateTimeString(),
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
            'title' => $schema->string()->description('The title of the feature.')->required(),
            'spec' => $schema->string()->description('The feature specification in markdown format. Describe what this feature does, acceptance criteria, and any technical notes.'),
            'project_slug' => $schema->string()->description('Slug of the project to assign the feature to. Use list-projects to find slugs.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('Feature priority. Default: medium.'),
            'due_date' => $schema->string()->description('Due date in YYYY-MM-DD format.'),
            'github_issue_number' => $schema->integer()->description('GitHub issue number this feature mirrors. When provided, the feature is upserted by this number (no duplicate on repeated syncs). Never sets status.'),
            'github_synced_hash' => $schema->string()->description('Digest of the source GitHub issue (title + body + labels + state). Used to gate natural-language rewrites between syncs.'),
        ];
    }
}
