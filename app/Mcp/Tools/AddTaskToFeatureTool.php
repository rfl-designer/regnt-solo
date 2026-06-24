<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddTaskToFeatureTool extends Tool
{
    protected string $name = 'add-task-to-feature';

    protected string $description = 'Links an existing task to a feature. The task will inherit the feature\'s project if it doesn\'t have one.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_id' => 'required|integer|exists:activities,id',
            'feature_id' => 'required|integer|exists:activities,id',
        ], [
            'task_id.required' => 'You must provide a task_id. Use list-tasks to find available task IDs.',
            'task_id.exists' => 'Task not found. Use list-tasks to find available task IDs.',
            'feature_id.required' => 'You must provide a feature_id. Use list-features to find available feature IDs.',
            'feature_id.exists' => 'Feature not found. Use list-features to find available feature IDs.',
        ]);

        $task = Activity::findOrFail($validated['task_id']);
        $feature = Activity::query()->epics()->findOrFail($validated['feature_id']);

        $updates = ['parent_id' => $feature->id];

        // Inherit project from feature if task doesn't have one
        if ($task->project_id === null && $feature->project_id !== null) {
            $updates['project_id'] = $feature->project_id;
        }

        $task->update($updates);
        $task->load(['project', 'parent']);

        $data = [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'feature_id' => $feature->id,
            'feature_title' => $feature->title,
            'project' => $task->project?->name,
            'message' => "Task \"{$task->title}\" linked to feature \"{$feature->title}\".",
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
        return [
            'task_id' => $schema->integer()->description('The ID of the task to link. Use list-tasks to find task IDs.')->required(),
            'feature_id' => $schema->integer()->description('The ID of the feature to link the task to. Use list-features to find feature IDs.')->required(),
        ];
    }
}
