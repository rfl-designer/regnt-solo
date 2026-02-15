<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetTaskTool extends Tool
{
    protected string $name = 'get-task';

    protected string $description = 'Gets a single task by ID with full details including project, time entries, and daily plans.';

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

        $task = Task::query()
            ->with(['project', 'timeEntries', 'dailyPlans'])
            ->findOrFail($validated['task_id']);

        $data = [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'project' => $task->project ? [
                'id' => $task->project->id,
                'name' => $task->project->name,
                'slug' => $task->project->slug,
            ] : null,
            'due_date' => $task->due_date?->toDateString(),
            'estimated_minutes' => $task->estimated_minutes,
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'is_overdue' => $task->isOverdue(),
            'is_running' => $task->isRunning(),
            'time_entries' => $task->timeEntries->map(fn ($entry) => [
                'id' => $entry->id,
                'started_at' => $entry->started_at->toDateTimeString(),
                'stopped_at' => $entry->stopped_at?->toDateTimeString(),
                'duration_minutes' => $entry->duration_minutes,
                'notes' => $entry->notes,
            ])->all(),
            'daily_plans' => $task->dailyPlans->map(fn ($plan) => [
                'id' => $plan->id,
                'date' => $plan->date->toDateString(),
            ])->all(),
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
            'task_id' => $schema->integer()->description('The ID of the task to retrieve.')->required(),
        ];
    }
}
