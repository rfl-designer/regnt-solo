<?php

namespace App\Mcp\Prompts;

use App\Models\StakeholderIssue;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class StakeholderIssuePlanningPrompt extends Prompt
{
    protected string $description = 'Reads a stakeholder issue and helps plan how to turn that issue into a feature.';

    /**
     * Get the prompt's arguments.
     *
     * @return array<int, \Laravel\Mcp\Server\Prompts\Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'issue_id',
                description: 'The stakeholder issue ID to plan.',
                required: true,
            ),
        ];
    }

    /**
     * Handle the prompt request.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        $issueId = $request->integer('issue_id');

        $issue = StakeholderIssue::query()
            ->with(['project', 'stakeholder', 'activity'])
            ->findOrFail($issueId);

        $context = "## Stakeholder Issue\n";
        $context .= "- ID: {$issue->id}\n";
        $context .= "- Status: {$issue->status->value}\n";
        $context .= "- Status Label: {$issue->status->label()}\n";
        $context .= "- Created At: {$issue->created_at->toDateTimeString()}\n";

        if ($issue->project) {
            $context .= "- Project: {$issue->project->emoji} {$issue->project->name}\n";
            $context .= "- Project Slug: {$issue->project->slug}\n";
        }

        if ($issue->stakeholder) {
            $context .= "- Stakeholder: {$issue->stakeholder->name} <{$issue->stakeholder->email}>\n";
        }

        $context .= "\n## Original Comment\n{$issue->comment}\n";

        if ($issue->activity) {
            $context .= "\n## Already Linked Feature\n";
            $context .= "- Feature ID: {$issue->activity->id}\n";
            $context .= "- Feature Title: {$issue->activity->title}\n";
            $context .= "- Feature Slug: {$issue->activity->slug}\n";
        }

        $systemMessage = 'You are a product planning assistant. '
            .'Analyze stakeholder feedback and convert it into actionable implementation planning. '
            .'When appropriate: '
            .'1) Clarify the real problem and expected outcome, '
            .'2) Draft a feature spec in markdown, '
            .'3) Suggest acceptance criteria and tasks, '
            .'4) Propose priority and due date, '
            .'5) Use promote-stakeholder-issue to create/link the feature. '
            .'Use list-stakeholder-issues to discover more context if needed.';

        return [
            Response::text($systemMessage)->asAssistant(),
            Response::text("Please plan this stakeholder issue and prepare it to become a feature:\n\n".$context),
        ];
    }
}
