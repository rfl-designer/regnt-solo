<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityType;
use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateDraftTool extends Tool
{
    protected string $name = 'create-draft';

    protected string $description = 'Creates a draft (type=Draft) — an immature idea that has not yet become roadmap. A draft is just a title and an optional note; it lives outside any status board, has no GitHub mirror and never appears on the stakeholder board. To turn a draft into roadmap, run /to-prd or /to-issues, which create the GitHub issue; the mirror then reflects it as an Epic or Issue. Returns the new draft id, title and note.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'note' => 'nullable|string',
        ], [
            'title.required' => 'You must provide a title for the draft.',
        ]);

        $draft = Activity::create([
            'type' => ActivityType::Draft,
            'title' => $validated['title'],
            'description' => $validated['note'] ?? null,
        ]);

        $data = [
            'id' => $draft->id,
            'title' => $draft->title,
            'note' => $draft->description,
            'created_at' => $draft->created_at->toDateTimeString(),
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
            'title' => $schema->string()->description('The title of the draft (the idea in one line).')->required(),
            'note' => $schema->string()->description('An optional note elaborating the idea, in markdown format.'),
        ];
    }
}
