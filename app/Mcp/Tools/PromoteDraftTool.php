<?php

namespace App\Mcp\Tools;

use App\Exceptions\ShapingIncompleteException;
use App\Models\Activity;
use App\Models\Project;
use App\Services\ShapingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Promote an Ideia to Épico, in the SoloBoard, over MCP (issue #148).
 *
 * This tool used to hand the draft's text off to `/to-prd` or `/to-issues`
 * and delete the local row, because the roadmap lived on GitHub (ADR 0001).
 * It no longer touches GitHub at all: shaping happens here, the bet is made
 * here, and the Épico is the very row the Ideia was.
 *
 * The gate is {@see ShapingService::promote()} — the same object the button
 * on the shaping page calls. That is deliberate and load-bearing: an agent
 * promoting over MCP gets the same "não", with the same words, as a human
 * clicking Promover, so MCP can never be the back door that puts an unshaped
 * bet on the board.
 */
#[IsDestructive]
class PromoteDraftTool extends Tool
{
    protected string $name = 'promote-draft';

    protected string $description = 'Promotes a draft (type=Draft) into an Épico in the SoloBoard. The draft is promoted *in place*: the same record becomes an Epic born in backlog with an empty Spec lifecycle, keeping its id, its dor (description), its esboço (spec) and its appetite. Nothing is created on GitHub. Requires the same four things the shaping page requires — Dor (description), Apetite (appetite_days), Esboço (spec) and a project — and refuses with the same message when any is missing; shape the draft first (update it, or use the shaping page at /ideas/{id}/shaping). Use get-pitch to read the draft or epic as markdown. Only operates on type=Draft.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ShapingService $shaping): Response
    {
        $validated = $request->validate([
            'draft_id' => 'required|integer|exists:activities,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'project_slug' => 'nullable|string|exists:projects,slug',
        ], [
            'draft_id.required' => 'You must provide a draft_id. Use list-drafts to find available draft ids.',
            'draft_id.exists' => 'Draft not found. Use list-drafts to find available draft ids.',
            'project_id.exists' => 'Project not found. Use list-projects to find available project ids.',
            'project_slug.exists' => 'Project not found. Use list-projects to find available project slugs.',
        ]);

        $draft = Activity::query()->drafts()->findOrFail($validated['draft_id']);

        $projectId = $validated['project_id'] ?? null;

        if ($projectId === null && ($validated['project_slug'] ?? null) !== null) {
            $projectId = Project::query()->where('slug', $validated['project_slug'])->value('id');
        }

        try {
            $epic = $shaping->promote($draft, $projectId);
        } catch (ShapingIncompleteException $e) {
            // The refusal is data, not a crash: the caller is expected to go
            // and fill in what is missing, so it needs to know what that is.
            return Response::error(json_encode([
                'promoted' => false,
                'missing' => $e->missing,
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return Response::text(json_encode([
            'promoted' => true,
            'id' => $epic->id,
            'type' => $epic->type->value,
            'title' => $epic->title,
            'slug' => $epic->slug,
            'status' => $epic->status->value,
            'project_id' => $epic->project_id,
            'appetite_days' => $epic->appetite_days,
            'spec_stage' => $epic->specStage(),
            'message' => "A Ideia virou o Épico #{$epic->id}, em Backlog, com o ciclo da Spec zerado.",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->description('The id of the draft to promote. Use list-drafts to find ids. Only type=Draft can be promoted.')->required(),
            'project_id' => $schema->integer()->description('The project the Épico belongs to. Required unless project_slug is given or the draft already has a project. An internal bet is a project without a client.'),
            'project_slug' => $schema->string()->description('The project slug, as an alternative to project_id.'),
        ];
    }
}
