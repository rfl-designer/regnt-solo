<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListDraftsTool extends Tool
{
    protected string $name = 'list-drafts';

    protected string $description = 'Lists drafts (type=Draft) — immature ideas that have not yet become roadmap. A draft is just a title and a note; it lives outside any status board, has no GitHub mirror and never appears on the stakeholder board. Reading a draft is the first step to promote it: read it here, then run /to-prd or /to-issues to create the GitHub issue. Returns draft id, title and note.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $limit = $validated['limit'] ?? 20;

        $drafts = Activity::query()->drafts()->latest()->limit($limit)->get();

        $data = $drafts->map(fn (Activity $draft) => [
            'id' => $draft->id,
            'title' => $draft->title,
            'note' => $draft->description,
            'created_at' => $draft->created_at->toDateTimeString(),
        ])->all();

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
            'limit' => $schema->integer()->description('Maximum number of drafts to return. Default: 20, max: 100.'),
        ];
    }
}
