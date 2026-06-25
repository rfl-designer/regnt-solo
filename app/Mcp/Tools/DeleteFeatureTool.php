<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteFeatureTool extends Tool
{
    protected string $name = 'delete-feature';

    protected string $description = 'Permanently deletes a feature and all its associated time entries. Tasks linked to this feature will have their feature_id set to null but will NOT be deleted. This action cannot be undone.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'feature_id' => 'required|integer|exists:activities,id',
        ], [
            'feature_id.required' => 'You must provide a feature_id. Use list-features to find available feature IDs.',
            'feature_id.exists' => 'Feature not found. Use list-features to find available feature IDs.',
        ]);

        $feature = Activity::query()->epics()->findOrFail($validated['feature_id']);

        $title = $feature->title;
        $tasksCount = $feature->children()->count();
        $timeEntriesCount = $feature->timeEntries()->count();

        // Unlink tasks from feature (don't delete them)
        $feature->children()->update(['parent_id' => null]);

        // Delete time entries and the feature
        $feature->timeEntries()->delete();
        $feature->delete();

        $data = [
            'deleted' => true,
            'feature_title' => $title,
            'tasks_unlinked' => $tasksCount,
            'time_entries_deleted' => $timeEntriesCount,
            'message' => "Feature \"{$title}\" deleted. {$tasksCount} tasks were unlinked (not deleted). {$timeEntriesCount} time entries were deleted.",
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
            'feature_id' => $schema->integer()->description('The ID of the feature to delete. This will also delete all associated time entries. Tasks will be unlinked but NOT deleted.')->required(),
        ];
    }
}
