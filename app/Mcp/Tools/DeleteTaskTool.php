<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteTaskTool extends Tool
{
    protected string $name = 'delete-task';

    protected string $description = 'Permanently deletes a task and all its associated time entries. This action cannot be undone.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'required|integer|exists:tasks,id',
        ], [
            'task_id.required' => 'You must provide a task_id. Use list-tasks to find available task IDs.',
            'task_id.exists' => 'Task not found. Use list-tasks to find available task IDs.',
        ]);

        $task = Task::findOrFail($validated['task_id']);
        $title = $task->title;
        $id = $task->id;

        $task->timeEntries()->delete();
        $task->delete();

        return Response::text(json_encode([
            'deleted' => true,
            'id' => $id,
            'title' => $title,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The ID of the task to delete. This will also delete all associated time entries.')->required(),
        ];
    }
}
