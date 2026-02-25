<?php

namespace App\Mcp\Tools;

use App\Models\Feature;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class StopTimerTool extends Tool
{
    protected string $name = 'stop-timer';

    protected string $description = 'Stops the running timer for a task or feature. Optionally add notes about what was accomplished during the time entry.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'nullable|integer|exists:tasks,id',
            'feature_id' => 'nullable|integer|exists:features,id',
            'notes' => 'nullable|string|max:1000',
        ], [
            'task_id.exists' => 'Task not found. Use list-tasks to find available task IDs.',
            'feature_id.exists' => 'Feature not found.',
        ]);

        $taskId = $validated['task_id'] ?? null;
        $featureId = $validated['feature_id'] ?? null;

        if ($taskId === null && $featureId === null) {
            return Response::error('You must provide either task_id or feature_id. Use timer-status to find the currently running timer.');
        }

        $notes = $validated['notes'] ?? null;

        if ($featureId !== null) {
            $feature = Feature::findOrFail($featureId);
            $entry = $feature->stopTimer($notes);

            if ($entry === null) {
                return Response::error("No running timer found for feature: {$feature->title}. Use start-timer to start one.");
            }

            $data = [
                'feature_id' => $feature->id,
                'feature_title' => $feature->title,
                'entry_id' => $entry->id,
                'started_at' => $entry->started_at->toDateTimeString(),
                'stopped_at' => $entry->stopped_at->toDateTimeString(),
                'duration_minutes' => $entry->duration_minutes,
                'notes' => $entry->notes,
                'message' => "Timer stopped for feature: {$feature->title} ({$entry->duration_minutes} minutes)",
            ];

            return Response::text(json_encode($data, JSON_PRETTY_PRINT));
        }

        $task = Task::findOrFail($taskId);

        $entry = TimeEntry::query()
            ->where('task_id', $task->id)
            ->running()
            ->latest('started_at')
            ->first();

        if (! $entry) {
            return Response::error("No running timer found for task: {$task->title}. Use start-timer to start one.");
        }

        $entry->update([
            'stopped_at' => now(),
            'notes' => $notes,
        ]);

        $data = [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'entry_id' => $entry->id,
            'started_at' => $entry->started_at->toDateTimeString(),
            'stopped_at' => $entry->stopped_at->toDateTimeString(),
            'duration_minutes' => $entry->duration_minutes,
            'notes' => $entry->notes,
            'message' => "Timer stopped for task: {$task->title} ({$entry->duration_minutes} minutes)",
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
            'task_id' => $schema->integer()->description('The ID of the task to stop the timer for.'),
            'feature_id' => $schema->integer()->description('The ID of the feature to stop the timer for. Alternative to task_id.'),
            'notes' => $schema->string()->description('Optional notes about what was accomplished during this time entry.'),
        ];
    }
}
