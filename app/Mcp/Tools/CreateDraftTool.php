<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityType;
use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateDraftTool extends Tool
{
    protected string $name = 'create-draft';

    protected string $description = 'Creates a draft (type=Draft) — an immature idea that has not been bet on yet. A draft is just a title and an optional note; it lives outside any status board and never appears on the stakeholder board. To turn a draft into an Épico, shape it first (Dor = description, Apetite = appetite_days, Esboço = spec, plus a project) and then call promote-draft, which promotes the very same record in place, inside the SoloBoard. Nothing on this path creates a GitHub issue, and nothing has to be synced afterwards. Returns the new draft id, title and note.';

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
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The title of the draft (the idea in one line).')->required(),
            'note' => $schema->string()->description('An optional note elaborating the idea, in markdown format.'),
        ];
    }
}
