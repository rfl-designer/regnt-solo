<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class StartTimerTool extends Tool
{
    protected string $name = 'start-timer';

    protected string $description = 'Starts a timer for a task. Automatically stops any currently running timer before starting the new one. Only one timer can run at a time.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'required|integer|exists:tasks,id',
            'focus' => 'nullable|boolean',
        ], [
            'task_id.required' => 'You must provide a task_id. Use list-tasks to find available task IDs.',
            'task_id.exists' => 'Task not found. Use list-tasks to find available task IDs.',
        ]);

        $task = Task::findOrFail($validated['task_id']);

        TimeEntry::stopAllRunning();

        $isFocus = $validated['focus'] ?? false;

        $entry = TimeEntry::create([
            'task_id' => $task->id,
            'started_at' => now(),
            'is_focus_session' => $isFocus,
        ]);

        $data = [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'entry_id' => $entry->id,
            'started_at' => $entry->started_at->toDateTimeString(),
            'is_focus_session' => $isFocus,
            'message' => $isFocus
                ? "Focus session started for task: {$task->title}"
                : "Timer started for task: {$task->title}",
        ];

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
            'task_id' => $schema->integer()->description('The ID of the task to start a timer for.')->required(),
            'focus' => $schema->boolean()->description('Start as a focus/deep work session. Default: false.'),
        ];
    }
}
