<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateFeatureTool extends Tool
{
    protected string $name = 'update-feature';

    protected string $description = 'Updates an existing feature. Provide only the fields you want to change.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'feature_id' => 'required|integer|exists:activities,id',
            'title' => 'nullable|string|max:255',
            'spec' => 'nullable|string',
            'project_slug' => 'nullable|string|exists:projects,slug',
            'priority' => ['nullable', 'string', Rule::enum(ActivityPriority::class)],
            'status' => ['nullable', 'string', Rule::enum(ActivityStatus::class)],
            'due_date' => 'nullable|date',
            'github_issue_number' => 'nullable|integer',
            'github_synced_hash' => 'nullable|string',
        ], [
            'feature_id.required' => 'You must provide a feature_id. Use list-features to find available feature IDs.',
            'feature_id.exists' => 'Feature not found. Use list-features to find available feature IDs.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'priority.Illuminate\Validation\Rules\Enum' => 'Invalid priority. Valid values: urgent, high, medium, low.',
            'status.Illuminate\Validation\Rules\Enum' => 'Invalid status. Valid values: backlog, todo, doing, done.',
        ]);

        $feature = Activity::query()->epics()->findOrFail($validated['feature_id']);

        $updates = [];

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
        }

        if (array_key_exists('spec', $validated)) {
            $updates['spec'] = $validated['spec'];
        }

        if (isset($validated['project_slug'])) {
            $updates['project_id'] = Project::query()->where('slug', $validated['project_slug'])->value('id');
        }

        if (isset($validated['priority'])) {
            $updates['priority'] = $validated['priority'];
        }

        if (isset($validated['status'])) {
            $updates['status'] = $validated['status'];
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
            $feature->update($updates);
        }

        $feature->load(['project', 'children']);

        $data = [
            'id' => $feature->id,
            'title' => $feature->title,
            'slug' => $feature->slug,
            'status' => $feature->status->value,
            'priority' => $feature->priority->value,
            'progress' => $feature->progress,
            'project' => $feature->project?->name,
            'due_date' => $feature->due_date?->toDateString(),
            'tasks_count' => $feature->tasksCount(),
            'is_running' => $feature->isRunning(),
            'github_issue_number' => $feature->github_issue_number,
            'github_synced_hash' => $feature->github_synced_hash,
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
            'feature_id' => $schema->integer()->description('The ID of the feature to update.')->required(),
            'title' => $schema->string()->description('New title for the feature.'),
            'spec' => $schema->string()->description('New specification in markdown format.'),
            'project_slug' => $schema->string()->description('Slug of the project to assign the feature to.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('New priority.'),
            'status' => $schema->string()->enum(['backlog', 'todo', 'doing', 'done'])->description('Feature status (manual). Default: backlog.'),
            'due_date' => $schema->string()->description('New due date in YYYY-MM-DD format.'),
            'github_issue_number' => $schema->integer()->description('GitHub issue number this feature mirrors (upsert key).'),
            'github_synced_hash' => $schema->string()->description('Digest of the source GitHub issue, used to gate natural-language rewrites.'),
        ];
    }
}
