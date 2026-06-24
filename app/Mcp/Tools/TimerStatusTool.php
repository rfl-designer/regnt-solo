<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityType;
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
            ->with('activity')
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

        if ($entry->activity_id !== null && $entry->activity !== null) {
            if ($entry->activity->type === ActivityType::Epic) {
                $data['feature_id'] = $entry->activity_id;
                $data['feature_title'] = $entry->activity->title;
            } else {
                $data['task_id'] = $entry->activity_id;
                $data['task_title'] = $entry->activity->title;
            }
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
