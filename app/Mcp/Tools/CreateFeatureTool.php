<?php

namespace App\Mcp\Tools;

use App\Enums\FeaturePriority;
use App\Enums\FeatureStatus;
use App\Models\Feature;
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
            'priority' => ['nullable', 'string', Rule::enum(FeaturePriority::class)],
            'due_date' => 'nullable|date',
        ], [
            'title.required' => 'You must provide a title for the feature.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'priority.Illuminate\Validation\Rules\Enum' => 'Invalid priority. Valid values: urgent, high, medium, low.',
        ]);

        $projectId = null;
        if (! empty($validated['project_slug'])) {
            $projectId = Project::query()->where('slug', $validated['project_slug'])->value('id');
        }

        $feature = Feature::create([
            'title' => $validated['title'],
            'spec' => $validated['spec'] ?? null,
            'project_id' => $projectId,
            'priority' => $validated['priority'] ?? FeaturePriority::Medium,
            'status' => FeatureStatus::Draft,
            'due_date' => $validated['due_date'] ?? null,
        ]);

        $feature->load('project');

        $data = [
            'id' => $feature->id,
            'title' => $feature->title,
            'slug' => $feature->slug,
            'status' => $feature->status->value,
            'priority' => $feature->priority->value,
            'project' => $feature->project?->name,
            'due_date' => $feature->due_date?->toDateString(),
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
        ];
    }
}
