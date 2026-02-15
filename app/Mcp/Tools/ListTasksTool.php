<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListTasksTool extends Tool
{
    protected string $name = 'list-tasks';

    protected string $description = 'Lists tasks with optional filtering by project slug, status, and limit. Returns task id, title, status, priority, project name, due_date, estimated_minutes, is_overdue, and is_running.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_slug' => 'nullable|string|exists:projects,slug',
            'status' => 'nullable|string|in:inbox,backlog,todo,doing,done',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Task::query()->with('project');

        if (! empty($validated['project_slug'])) {
            $project = Project::query()->where('slug', $validated['project_slug'])->first();
            $query->where('project_id', $project->id);
        }

        if (! empty($validated['status'])) {
            $query->byStatus(TaskStatus::from($validated['status']));
        }

        $limit = $validated['limit'] ?? 20;

        $tasks = $query->latest()->limit($limit)->get();

        $data = $tasks->map(fn (Task $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'project' => $task->project?->name,
            'due_date' => $task->due_date?->toDateString(),
            'estimated_minutes' => $task->estimated_minutes,
            'is_overdue' => $task->isOverdue(),
            'is_running' => $task->isRunning(),
        ])->all();

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
            'project_slug' => $schema->string()->description('Filter tasks by project slug. Optional.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'todo', 'doing', 'done'])->description('Filter tasks by status. Optional.'),
            'limit' => $schema->integer()->description('Maximum number of tasks to return. Default: 20, max: 100.'),
        ];
    }
}
