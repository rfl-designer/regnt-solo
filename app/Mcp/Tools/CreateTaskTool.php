<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateTaskTool extends Tool
{
    protected string $name = 'create-task';

    protected string $description = 'Creates a new task. By default, tasks are created with status "inbox" and priority "medium". Optionally assign to a project by slug.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_slug' => 'nullable|string|exists:projects,slug',
            'status' => ['nullable', 'string', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', 'string', Rule::enum(TaskPriority::class)],
            'due_date' => 'nullable|date',
            'estimated_minutes' => 'nullable|integer|min:1',
        ], [
            'title.required' => 'You must provide a title for the task.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
            'status.Illuminate\Validation\Rules\Enum' => 'Invalid status. Valid values: inbox, backlog, todo, doing, done.',
            'priority.Illuminate\Validation\Rules\Enum' => 'Invalid priority. Valid values: urgent, high, medium, low.',
        ]);

        $projectId = null;
        if (! empty($validated['project_slug'])) {
            $projectId = Project::query()->where('slug', $validated['project_slug'])->value('id');
        }

        $task = Task::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'project_id' => $projectId,
            'status' => $validated['status'] ?? TaskStatus::Inbox,
            'priority' => $validated['priority'] ?? TaskPriority::Medium,
            'due_date' => $validated['due_date'] ?? null,
            'estimated_minutes' => $validated['estimated_minutes'] ?? null,
        ]);

        $task->load('project');

        $data = [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'project' => $task->project?->name,
            'due_date' => $task->due_date?->toDateString(),
            'estimated_minutes' => $task->estimated_minutes,
            'created_at' => $task->created_at->toDateTimeString(),
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
            'title' => $schema->string()->description('The title of the task.')->required(),
            'description' => $schema->string()->description('Optional description or notes for the task.'),
            'project_slug' => $schema->string()->description('Slug of the project to assign the task to. Use list-projects to find slugs.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'todo', 'doing', 'done'])->description('Task status. Default: inbox.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('Task priority. Default: medium.'),
            'due_date' => $schema->string()->description('Due date in YYYY-MM-DD format.'),
            'estimated_minutes' => $schema->integer()->description('Estimated time in minutes to complete the task.'),
        ];
    }
}
