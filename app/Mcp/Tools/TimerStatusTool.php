<?php

namespace App\Mcp\Tools;

use App\Models\TimeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class TimerStatusTool extends Tool
{
    protected string $name = 'timer-status';

    protected string $description = 'Shows the currently running timer, if any. Returns task info and elapsed time.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $entry = TimeEntry::query()
            ->with(['task', 'feature'])
            ->running()
            ->latest('started_at')
            ->first();

        if (! $entry) {
            return Response::text(json_encode([
                'running' => false,
                'message' => 'No timer is currently running. Use start-timer to start one.',
            ], JSON_PRETTY_PRINT));
        }

        $data = [
            'running' => true,
            'entry_id' => $entry->id,
            'started_at' => $entry->started_at->toDateTimeString(),
            'duration_minutes' => $entry->duration_minutes,
            'is_focus_session' => $entry->is_focus_session,
        ];

        if ($entry->feature_id !== null && $entry->feature !== null) {
            $data['feature_id'] = $entry->feature_id;
            $data['feature_title'] = $entry->feature->title;
        }

        if ($entry->task_id !== null && $entry->task !== null) {
            $data['task_id'] = $entry->task_id;
            $data['task_title'] = $entry->task->title;
        }

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
