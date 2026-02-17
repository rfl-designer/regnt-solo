<?php

namespace App\Mcp\Tools;

use App\Models\RecurringTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ToggleRecurringTaskTool extends Tool
{
    protected string $name = 'toggle-recurring-task';

    protected string $description = 'Activates or pauses a recurring task. Paused tasks will not generate new tasks.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'recurring_task_id' => 'required|integer|exists:recurring_tasks,id',
            'is_active' => 'nullable|boolean',
        ]);

        $recurring = RecurringTask::findOrFail($validated['recurring_task_id']);

        $newStatus = $validated['is_active'] ?? ! $recurring->is_active;
        $recurring->update(['is_active' => $newStatus]);

        $statusLabel = $newStatus ? 'ativada' : 'pausada';

        return Response::text(json_encode([
            'success' => true,
            'message' => "Task recorrente '{$recurring->title}' foi {$statusLabel}.",
            'recurring_task' => [
                'id' => $recurring->id,
                'title' => $recurring->title,
                'is_active' => $recurring->is_active,
                'next_run' => $recurring->next_run->toDateString(),
            ],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'recurring_task_id' => $schema->integer()->description('The ID of the recurring task to toggle.')->required(),
            'is_active' => $schema->boolean()->description('Set to true to activate, false to pause. If omitted, toggles current state.'),
        ];
    }
}
