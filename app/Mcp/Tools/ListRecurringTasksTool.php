<?php

namespace App\Mcp\Tools;

use App\Models\RecurringTask;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListRecurringTasksTool extends Tool
{
    protected string $name = 'list-recurring-tasks';

    protected string $description = 'Lists all recurring tasks. Returns id, title, frequency, next_run, is_active, project name, and priority.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'active_only' => 'nullable|boolean',
        ]);

        $query = RecurringTask::query()->with('project');

        if (($validated['active_only'] ?? false) === true) {
            $query->active();
        }

        $recurringTasks = $query
            ->orderByDesc('is_active')
            ->orderBy('next_run')
            ->get();

        $data = $recurringTasks->map(fn (RecurringTask $recurring) => [
            'id' => $recurring->id,
            'title' => $recurring->title,
            'frequency' => $recurring->frequency->value,
            'frequency_label' => $recurring->frequency->label(),
            'next_run' => $recurring->next_run->toDateString(),
            'last_run' => $recurring->last_run?->toDateString(),
            'is_active' => $recurring->is_active,
            'is_due' => $recurring->isDue(),
            'project' => $recurring->project?->name,
            'priority' => $recurring->priority->value,
            'estimated_minutes' => $recurring->estimated_minutes,
        ])->all();

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
            'active_only' => $schema->boolean()->description('If true, only returns active recurring tasks.'),
        ];
    }
}
