<?php

namespace App\Mcp\Tools;

use App\Models\DailyPlan;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class TodayPlanTool extends Tool
{
    protected string $name = 'today-plan';

    protected string $description = 'Gets today\'s daily plan with tasks, completion rate, and notes. Creates the plan automatically if it doesn\'t exist yet.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $plan = DailyPlan::getOrCreateForDate(Carbon::today());
        $plan->load('tasks.project');

        $tasks = $plan->tasks->map(fn ($task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'project' => $task->project?->name,
            'completed_in_plan' => $task->pivot->completed_at !== null,
            'completed_at' => $task->pivot->completed_at,
        ])->all();

        $data = [
            'plan_id' => $plan->id,
            'date' => $plan->date->toDateString(),
            'notes' => $plan->notes,
            'completion_rate' => $plan->completionRate(),
            'total_tasks' => count($tasks),
            'tasks' => $tasks,
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
        return [];
    }
}
