<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\DailyPlan;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SuggestTasksTool extends Tool
{
    protected string $name = 'suggest-tasks';

    protected string $description = 'Suggests up to 10 priority tasks that are not yet in today\'s plan. Prioritizes: overdue tasks first, then "doing" tasks, then "todo" tasks ordered by priority.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $plan = DailyPlan::getOrCreateForDate(Carbon::today());
        $existingTaskIds = $plan->tasks()->pluck('activities.id')->all();

        $suggestions = collect();

        // 1. Overdue tasks first
        $overdue = Activity::query()
            ->with('project')
            ->overdue()
            ->whereNotIn('id', $existingTaskIds)
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END")
            ->get();
        $suggestions = $suggestions->merge($overdue);

        // 2. Doing tasks
        if ($suggestions->count() < 10) {
            $doing = Activity::query()
                ->with('project')
                ->byStatus(ActivityStatus::Doing)
                ->whereNotIn('id', $existingTaskIds)
                ->whereNotIn('id', $suggestions->pluck('id'))
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END")
                ->get();
            $suggestions = $suggestions->merge($doing);
        }

        // 3. Todo tasks ordered by priority
        if ($suggestions->count() < 10) {
            $remaining = 10 - $suggestions->count();
            $todo = Activity::query()
                ->with('project')
                ->byStatus(ActivityStatus::Todo)
                ->whereNotIn('id', $existingTaskIds)
                ->whereNotIn('id', $suggestions->pluck('id'))
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END")
                ->limit($remaining)
                ->get();
            $suggestions = $suggestions->merge($todo);
        }

        $data = $suggestions->take(10)->map(fn (Activity $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'service_class' => $task->service_class->value,
            'project' => $task->project?->name,
            'due_date' => $task->due_date?->toDateString(),
            'is_overdue' => $task->isOverdue(),
            'reason' => $task->isOverdue() ? 'overdue' : ($task->status->value === 'doing' ? 'in progress' : 'todo by priority'),
        ])->values()->all();

        return Response::text(json_encode([
            'suggestions' => $data,
            'count' => count($data),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
