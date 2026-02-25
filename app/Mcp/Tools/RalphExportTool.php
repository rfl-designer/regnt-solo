<?php

namespace App\Mcp\Tools;

use App\Console\Commands\RalphExportCommand;
use App\Models\Feature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class RalphExportTool extends Tool
{
    protected string $name = 'ralph-export';

    protected string $description = 'Exports a Feature as a Ralph-compatible PRD (prd.json format). Returns the JSON structure with user stories mapped from the feature tasks. Use this to generate Ralph loop input without running the Artisan command.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'feature_id' => 'required|integer|exists:features,id',
            'max_iterations' => 'integer|min:1|max:100',
        ], [
            'feature_id.required' => 'You must provide a feature_id. Use list-features to find available feature IDs.',
            'feature_id.exists' => 'Feature not found. Use list-features to find available feature IDs.',
        ]);

        $feature = Feature::query()
            ->with(['project', 'tasks.project'])
            ->findOrFail($validated['feature_id']);

        $maxIterations = $validated['max_iterations'] ?? 10;

        $command = new RalphExportCommand;
        $prd = $command->buildPrd($feature, $maxIterations);

        return Response::text(json_encode($prd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'feature_id' => $schema->integer()->description('The ID of the feature to export as Ralph PRD.')->required(),
            'max_iterations' => $schema->integer()->description('Maximum Ralph loop iterations (default: 10).'),
        ];
    }
}
