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
            ->with('task')
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
            'task_id' => $entry->task_id,
            'task_title' => $entry->task->title,
            'entry_id' => $entry->id,
            'started_at' => $entry->started_at->toDateTimeString(),
            'duration_minutes' => $entry->duration_minutes,
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
        return [];
    }
}
