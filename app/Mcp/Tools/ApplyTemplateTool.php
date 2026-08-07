<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\TaskTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ApplyTemplateTool extends Tool
{
    protected string $name = 'apply-template';

    protected string $description = 'Creates a new task using a template. The template defines default title, description, priority, and estimated time.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'template_slug' => 'required|string|exists:task_templates,slug',
            'title_override' => 'nullable|string|max:255',
            'project_slug' => 'nullable|string|exists:projects,slug',
        ]);

        $template = TaskTemplate::query()->where('slug', $validated['template_slug'])->firstOrFail();

        $overrides = [];

        if (! empty($validated['title_override'])) {
            $overrides['title'] = $validated['title_override'];
        }

        if (! empty($validated['project_slug'])) {
            $project = Project::query()->where('slug', $validated['project_slug'])->firstOrFail();
            $overrides['project_id'] = $project->id;
        }

        $task = $template->createTask($overrides);

        return Response::text(json_encode([
            'success' => true,
            'message' => "Task '{$task->title}' created from template '{$template->name}'.",
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status->value,
                'service_class' => $task->service_class->value,
                'estimated_minutes' => $task->estimated_minutes,
            ],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'template_slug' => $schema->string()->description('The slug of the template to use. Use list-templates to find available slugs.')->required(),
            'title_override' => $schema->string()->description('Optional custom title to override the template default.'),
            'project_slug' => $schema->string()->description('Optional project slug to assign the task to.'),
        ];
    }
}
