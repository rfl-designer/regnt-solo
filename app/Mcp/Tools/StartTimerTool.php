<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class StartTimerTool extends Tool
{
    protected string $name = 'start-timer';

    protected string $description = 'Starts a timer for a task or feature. Automatically stops any currently running timer before starting the new one. Only one timer can run at a time.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'nullable|integer|exists:activities,id',
            'feature_id' => 'nullable|integer|exists:activities,id',
            'focus' => 'nullable|boolean',
        ], [
            'task_id.exists' => 'Task not found. Use list-tasks to find available task IDs.',
            'feature_id.exists' => 'Feature not found.',
        ]);

        $taskId = $validated['task_id'] ?? null;
        $featureId = $validated['feature_id'] ?? null;

        if ($taskId === null && $featureId === null) {
            return Response::error('You must provide either task_id or feature_id. Use list-tasks to find available task IDs.');
        }

        $isFocus = $validated['focus'] ?? false;

        if ($featureId !== null) {
            $feature = Activity::query()->epics()->findOrFail($featureId);
            $entry = $feature->startTimer($isFocus);

            $data = [
                'feature_id' => $feature->id,
                'feature_title' => $feature->title,
                'entry_id' => $entry->id,
                'started_at' => $entry->started_at->toDateTimeString(),
                'is_focus_session' => $isFocus,
                'message' => $isFocus
                    ? "Focus session started for feature: {$feature->title}"
                    : "Timer started for feature: {$feature->title}",
            ];

            return Response::text(json_encode($data, JSON_PRETTY_PRINT));
        }

        $task = Activity::findOrFail($taskId);
        $entry = $task->startTimer($isFocus);
        $task->refresh();

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
            'task_id' => $schema->integer()->description('The ID of the task to start a timer for.'),
            'feature_id' => $schema->integer()->description('The ID of the feature to start a timer for. Alternative to task_id.'),
            'focus' => $schema->boolean()->description('Start as a focus/deep work session. Default: false.'),
        ];
    }
}
