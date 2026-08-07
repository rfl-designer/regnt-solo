<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListClientsTool extends Tool
{
    protected string $name = 'list-clients';

    protected string $description = 'Lists clients, including those with no project (linked directly to tasks or stakeholders). Use this to find a client_id for create-task, the project form, or stakeholder linking. Filters by active status (default: only active clients).';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'include_archived' => 'nullable|boolean',
        ]);

        $query = Client::query()->orderBy('name');

        if (empty($validated['include_archived'])) {
            $query->active();
        }

        $clients = $query->get();

        $data = $clients->map(fn (Client $client) => [
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'color' => $client->color,
            'is_active' => $client->is_active,
        ])->all();

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
            'include_archived' => $schema->boolean()->description('Include archived (inactive) clients. Default: false.'),
        ];
    }
}
