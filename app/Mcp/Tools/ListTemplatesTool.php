<?php

namespace App\Mcp\Tools;

use App\Models\TaskTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListTemplatesTool extends Tool
{
    protected string $name = 'list-templates';

    protected string $description = 'Lists all task templates available. Returns template id, name, slug, default_priority, default_estimated_minutes, and is_system.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $templates = TaskTemplate::query()
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        $data = $templates->map(fn (TaskTemplate $template) => [
            'id' => $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
            'default_priority' => $template->default_priority->value,
            'default_estimated_minutes' => $template->default_estimated_minutes,
            'icon' => $template->icon,
            'color' => $template->color,
            'is_system' => $template->is_system,
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
        return [];
    }
}
