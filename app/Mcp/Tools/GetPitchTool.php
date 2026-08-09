<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Services\ShapingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The pitch of a bet, as markdown, over MCP (issue #148).
 *
 * Rendered by {@see ShapingService::pitch()} — the same call behind "Copiar
 * pitch" on the shaping page and in the Épico modal. There is one template
 * and no stored copy, so what an agent reads here is exactly what the user
 * would have pasted from either screen.
 */
#[IsReadOnly]
class GetPitchTool extends Tool
{
    protected string $name = 'get-pitch';

    protected string $description = 'Renders the deterministic markdown pitch of a shaped Ideia (type=Draft) or of an Épico it was promoted into: the five shaping sections — Dor, Apetite, Esboço, Rabbit holes, No-gos — always in that order, with empty sections shown as empty rather than dropped. Read-only and stored nowhere, so it never goes stale. This is the same text the "Copiar pitch" button produces in the app.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ShapingService $shaping): Response
    {
        $validated = $request->validate([
            'activity_id' => 'required|integer|exists:activities,id',
        ], [
            'activity_id.required' => 'You must provide an activity_id (a draft or an epic). Use list-drafts or list-epics to find ids.',
            'activity_id.exists' => 'Activity not found. Use list-drafts or list-epics to find ids.',
        ]);

        $activity = Activity::query()
            ->whereIn('type', [ActivityType::Draft->value, ActivityType::Epic->value])
            ->findOrFail($validated['activity_id']);

        return Response::text($shaping->pitch($activity));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'activity_id' => $schema->integer()->description('The id of the draft or epic to render as a pitch.')->required(),
        ];
    }
}
