<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

class SoloBoardServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'SoloBoard';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * Default pagination length for tools/resources/prompts listing.
     */
    public int $defaultPaginationLength = 50;

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = 'SoloBoard is a personal productivity app for solo developers. It manages a roadmap mirrored one-way from GitHub plus a personal layer, projects, stakeholder issues, time tracking, and daily planning. The roadmap has two types: Epics (top-level, mirror GitHub type:prd issues) and Issues (mirror other GitHub issues; their client-facing label Fatia/Follow-up/Avulsa is derived from the parent). Use list-epics, create-epic, update-epic to manage epics; the epic status is manual (create-epic never sets it). Use list-issues, create-issue, update-issue, delete-issue to manage issues; create/update accept project_id, parent_id and status, and setting status to done marks the issue done. Both epics and issues upsert by github_issue_number for idempotent syncs; delete-issue cascades time entries and is used for reconciliation. Statuses are inbox, backlog, todo, doing, done; priorities are urgent, high, medium, low. Stakeholder issues track external feedback. Projects have slugs and ids for identification. Timers are time entries linked to an activity — only one timer can run at a time. Documents are markdown pages (PRDs, specs, decisions, notes) that belong to projects. Use list-stakeholder-issues and promote-stakeholder-issue for feedback triage. Use get-project-context for full project overview.';

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        \App\Mcp\Tools\StartTimerTool::class,
        \App\Mcp\Tools\StopTimerTool::class,
        \App\Mcp\Tools\TimerStatusTool::class,
        \App\Mcp\Tools\TodayPlanTool::class,
        \App\Mcp\Tools\SuggestTasksTool::class,
        \App\Mcp\Tools\AddToPlanTool::class,
        \App\Mcp\Tools\ListProjectsTool::class,
        \App\Mcp\Tools\LogCommitsTool::class,
        \App\Mcp\Tools\GetAnalyticsTool::class,
        \App\Mcp\Tools\ListDocumentsTool::class,
        \App\Mcp\Tools\GetDocumentTool::class,
        \App\Mcp\Tools\CreateDocumentTool::class,
        \App\Mcp\Tools\UpdateDocumentTool::class,
        \App\Mcp\Tools\DeleteDocumentTool::class,
        \App\Mcp\Tools\GetProjectContextTool::class,
        \App\Mcp\Tools\ListTemplatesTool::class,
        \App\Mcp\Tools\ApplyTemplateTool::class,
        \App\Mcp\Tools\ListRecurringTasksTool::class,
        \App\Mcp\Tools\CreateRecurringTaskTool::class,
        \App\Mcp\Tools\ToggleRecurringTaskTool::class,
        \App\Mcp\Tools\ListEpicsTool::class,
        \App\Mcp\Tools\CreateEpicTool::class,
        \App\Mcp\Tools\UpdateEpicTool::class,
        \App\Mcp\Tools\ListIssuesTool::class,
        \App\Mcp\Tools\CreateIssueTool::class,
        \App\Mcp\Tools\UpdateIssueTool::class,
        \App\Mcp\Tools\DeleteIssueTool::class,
        \App\Mcp\Tools\ListTasksTool::class,
        \App\Mcp\Tools\CreateTaskTool::class,
        \App\Mcp\Tools\RalphExportTool::class,
        \App\Mcp\Tools\ListStakeholderIssuesTool::class,
        \App\Mcp\Tools\PromoteStakeholderIssueToFeatureTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        \App\Mcp\Resources\ProjectOverviewResource::class,
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        \App\Mcp\Prompts\DailyPlanningPrompt::class,
        \App\Mcp\Prompts\SessionPlanningPrompt::class,
        \App\Mcp\Prompts\DevelopmentWorkflowPrompt::class,
        \App\Mcp\Prompts\FeaturePlanningPrompt::class,
        \App\Mcp\Prompts\StakeholderIssuePlanningPrompt::class,
    ];
}
