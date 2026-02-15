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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateTaskTool extends Tool
{
    protected string $name = 'update-task';

    protected string $description = 'Updates an existing task. When status is changed to "done", the task is automatically marked as done (stops running timers and sets completed_at). Provide only the fields you want to change.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'required|integer|exists:tasks,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'project_slug' => 'nullable|string|exists:projects,slug',
            'status' => ['nullable', 'string', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', 'string', Rule::enum(TaskPriority::class)],
            'due_date' => 'nullable|date',
            'estimated_minutes' => 'nullable|integer|min:1',
        ], [
            'task_id.required' => 'You must provide a task_id. Use list-tasks to find available task IDs.',
            'task_id.exists' => 'Task not found. Use list-tasks to find available task IDs.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
        ]);

        $task = Task::findOrFail($validated['task_id']);

        // If status is being changed to done, use markAsDone()
        if (isset($validated['status']) && $validated['status'] === TaskStatus::Done->value && $task->status !== TaskStatus::Done) {
            $task->markAsDone();
            $task->refresh();
        }

        $updates = [];

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
        }

        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }

        if (isset($validated['project_slug'])) {
            $updates['project_id'] = Project::query()->where('slug', $validated['project_slug'])->value('id');
        }

        if (isset($validated['status']) && $validated['status'] !== TaskStatus::Done->value) {
            $updates['status'] = $validated['status'];
        }

        if (isset($validated['priority'])) {
            $updates['priority'] = $validated['priority'];
        }

        if (isset($validated['due_date'])) {
            $updates['due_date'] = $validated['due_date'];
        }

        if (isset($validated['estimated_minutes'])) {
            $updates['estimated_minutes'] = $validated['estimated_minutes'];
        }

        if (! empty($updates)) {
            $task->update($updates);
        }

        $task->load('project');

        $data = [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'project' => $task->project?->name,
            'due_date' => $task->due_date?->toDateString(),
            'estimated_minutes' => $task->estimated_minutes,
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'is_overdue' => $task->isOverdue(),
            'is_running' => $task->isRunning(),
            'updated_at' => $task->updated_at->toDateTimeString(),
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
            'task_id' => $schema->integer()->description('The ID of the task to update.')->required(),
            'title' => $schema->string()->description('New title for the task.'),
            'description' => $schema->string()->description('New description for the task.'),
            'project_slug' => $schema->string()->description('Slug of the project to assign the task to.'),
            'status' => $schema->string()->enum(['inbox', 'backlog', 'todo', 'doing', 'done'])->description('New status. Setting to "done" will stop running timers and set completed_at.'),
            'priority' => $schema->string()->enum(['urgent', 'high', 'medium', 'low'])->description('New priority.'),
            'due_date' => $schema->string()->description('New due date in YYYY-MM-DD format.'),
            'estimated_minutes' => $schema->integer()->description('New estimated time in minutes.'),
        ];
    }
}
