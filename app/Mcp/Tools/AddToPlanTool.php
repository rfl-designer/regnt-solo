<?php

namespace App\Mcp\Tools;

use App\Models\DailyPlan;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddToPlanTool extends Tool
{
    protected string $name = 'add-to-plan';

    protected string $description = 'Adds a task to today\'s daily plan. Creates the plan automatically if it doesn\'t exist. Prevents duplicate additions.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'required|integer|exists:tasks,id',
        ], [
            'task_id.required' => 'You must provide a task_id. Use list-tasks or suggest-tasks to find task IDs.',
            'task_id.exists' => 'Task not found. Use list-tasks to find available task IDs.',
        ]);

        $task = Task::findOrFail($validated['task_id']);
        $plan = DailyPlan::getOrCreateForDate(Carbon::today());

        // Check if task is already in the plan
        if ($plan->tasks()->where('tasks.id', $task->id)->exists()) {
            return Response::text(json_encode([
                'added' => false,
                'message' => "Task \"{$task->title}\" is already in today's plan.",
                'plan_id' => $plan->id,
            ], JSON_PRETTY_PRINT));
        }

        $maxOrder = $plan->tasks()->max('daily_plan_task.sort_order') ?? 0;
        $plan->tasks()->attach($task->id, ['sort_order' => $maxOrder + 1]);

        $plan->load('tasks.project');

        $data = [
            'added' => true,
            'task_id' => $task->id,
            'task_title' => $task->title,
            'plan_id' => $plan->id,
            'date' => $plan->date->toDateString(),
            'total_tasks' => $plan->tasks->count(),
            'message' => "Task \"{$task->title}\" added to today's plan.",
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
            'task_id' => $schema->integer()->description('The ID of the task to add to today\'s plan.')->required(),
        ];
    }
}
