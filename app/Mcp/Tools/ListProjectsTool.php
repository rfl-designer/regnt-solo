<?php

namespace App\Mcp\Tools;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListProjectsTool extends Tool
{
    protected string $name = 'list-projects';

    protected string $description = 'Lists all projects with their details and active task counts. Filters by status (default: active).';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::enum(ProjectStatus::class)],
        ], [
            'status.Illuminate\Validation\Rules\Enum' => 'Invalid status. Valid values: active, paused, archived.',
        ]);

        $query = Project::query()
            ->withCount(['tasks as active_tasks_count' => fn ($q) => $q->active()])
            ->ordered();

        $status = $validated['status'] ?? 'active';
        $query->where('status', $status);

        $projects = $query->get();

        $data = $projects->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'color' => $project->color,
            'emoji' => $project->emoji,
            'status' => $project->status->value,
            'priority' => $project->priority->value,
            'description' => $project->description,
            'active_tasks_count' => $project->active_tasks_count,
        ])->all();

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['active', 'paused', 'archived'])
                ->description('Filter projects by status. Default: active.'),
        ];
    }
}
