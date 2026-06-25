<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteIssueTool extends Tool
{
    protected string $name = 'delete-issue';

    protected string $description = 'Permanently deletes a roadmap issue (type=Issue) and all its associated time entries (cascade). Used by the sync to reconcile: an issue that became wontfix or was removed on GitHub is deleted so the board never shows a dead card. Only operates on type=Issue — never touches Epic, Task or Draft. This action cannot be undone.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'issue_id' => 'required|integer|exists:activities,id',
        ], [
            'issue_id.required' => 'You must provide an issue_id. Use list-issues to find available issue ids.',
            'issue_id.exists' => 'Issue not found. Use list-issues to find available issue ids.',
        ]);

        $issue = Activity::query()->issues()->findOrFail($validated['issue_id']);

        $title = $issue->title;
        $id = $issue->id;
        $timeEntriesCount = $issue->timeEntries()->count();

        $issue->timeEntries()->delete();
        $issue->delete();

        return Response::text(json_encode([
            'deleted' => true,
            'id' => $id,
            'title' => $title,
            'time_entries_deleted' => $timeEntriesCount,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issue_id' => $schema->integer()->description('The id of the issue to delete. This will also delete all associated time entries (cascade). Only type=Issue can be deleted here.')->required(),
        ];
    }
}
