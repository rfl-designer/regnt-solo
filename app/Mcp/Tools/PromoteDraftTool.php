<?php

namespace App\Mcp\Tools;

use App\Models\Activity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class PromoteDraftTool extends Tool
{
    protected string $name = 'promote-draft';

    protected string $description = 'Promotes a draft (type=Draft) into roadmap. Promotion is a one-way handoff: a draft is born into the roadmap on GitHub, never by a local type mutation (ADR 0001). This tool returns the draft title and note as the seed for /to-prd (target=prd, for a PRD that becomes an Epic) or /to-issues (target=issues, for issues), then removes the local draft — the roadmap mirror will reflect the resulting Epic or Issue on the next sync. After calling this, run the chosen skill with the returned content to create the GitHub issue. Only operates on type=Draft.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'draft_id' => 'required|integer|exists:activities,id',
            'target' => ['required', 'string', Rule::in(['prd', 'issues'])],
        ], [
            'draft_id.required' => 'You must provide a draft_id. Use list-drafts to find available draft ids.',
            'draft_id.exists' => 'Draft not found. Use list-drafts to find available draft ids.',
            'target.required' => 'You must provide a target: "prd" (run /to-prd) or "issues" (run /to-issues).',
            'target.in' => 'Invalid target. Valid values: "prd" (run /to-prd) or "issues" (run /to-issues).',
        ]);

        $draft = Activity::query()->drafts()->findOrFail($validated['draft_id']);

        $title = $draft->title;
        $note = $draft->description;
        $skill = $validated['target'] === 'prd' ? '/to-prd' : '/to-issues';

        $draft->delete();

        $data = [
            'promoted' => true,
            'skill' => $skill,
            'title' => $title,
            'note' => $note,
            'instructions' => "The local draft has been removed. Run {$skill} using the title and note above to create the GitHub issue; the roadmap mirror will reflect the new ".($validated['target'] === 'prd' ? 'Epic' : 'Issue').' on the next sync.',
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
            'draft_id' => $schema->integer()->description('The id of the draft to promote. Use list-drafts to find ids. Only type=Draft can be promoted.')->required(),
            'target' => $schema->string()->enum(['prd', 'issues'])->description('Promotion target: "prd" to seed /to-prd (becomes an Epic) or "issues" to seed /to-issues.')->required(),
        ];
    }
}
