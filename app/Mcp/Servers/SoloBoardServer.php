<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DailyPlanningPrompt;
use App\Mcp\Prompts\DevelopmentWorkflowPrompt;
use App\Mcp\Prompts\FeaturePlanningPrompt;
use App\Mcp\Prompts\SessionPlanningPrompt;
use App\Mcp\Prompts\StakeholderIssuePlanningPrompt;
use App\Mcp\Resources\ProjectOverviewResource;
use App\Mcp\Tools\AddToPlanTool;
use App\Mcp\Tools\ApplyTemplateTool;
use App\Mcp\Tools\CreateDocumentTool;
use App\Mcp\Tools\CreateDraftTool;
use App\Mcp\Tools\CreateEpicTool;
use App\Mcp\Tools\CreateIssueTool;
use App\Mcp\Tools\CreateRecurringTaskTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\DeleteDocumentTool;
use App\Mcp\Tools\DeleteIssueTool;
use App\Mcp\Tools\GetAnalyticsTool;
use App\Mcp\Tools\GetDocumentTool;
use App\Mcp\Tools\GetProjectContextTool;
use App\Mcp\Tools\ListClientsTool;
use App\Mcp\Tools\ListDocumentsTool;
use App\Mcp\Tools\ListDraftsTool;
use App\Mcp\Tools\ListEpicsTool;
use App\Mcp\Tools\ListIssuesTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListRecurringTasksTool;
use App\Mcp\Tools\ListStakeholderIssuesTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ListTemplatesTool;
use App\Mcp\Tools\LogCommitsTool;
use App\Mcp\Tools\PromoteDraftTool;
use App\Mcp\Tools\PromoteStakeholderIssueToFeatureTool;
use App\Mcp\Tools\RalphExportTool;
use App\Mcp\Tools\StartTimerTool;
use App\Mcp\Tools\StopTimerTool;
use App\Mcp\Tools\SuggestTasksTool;
use App\Mcp\Tools\TimerStatusTool;
use App\Mcp\Tools\TodayPlanTool;
use App\Mcp\Tools\ToggleRecurringTaskTool;
use App\Mcp\Tools\UpdateDocumentTool;
use App\Mcp\Tools\UpdateEpicTool;
use App\Mcp\Tools\UpdateIssueTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

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
    protected string $instructions = 'SoloBoard is a personal productivity app for solo developers. It manages a roadmap mirrored one-way from GitHub plus a personal layer, projects, stakeholder issues, time tracking, and daily planning. The roadmap has two types: Epics (top-level, mirror GitHub type:prd issues) and Issues (mirror other GitHub issues; their client-facing label Fatia/Follow-up/Avulsa is derived from the parent). Use list-epics, create-epic, update-epic to manage epics; the epic status is manual (create-epic never sets it). Use list-issues, create-issue, update-issue, delete-issue to manage issues; create/update accept project_id, parent_id and status, and setting status to done marks the issue done. Both epics and issues upsert by github_issue_number for idempotent syncs; delete-issue cascades time entries and is used for reconciliation. Use list-drafts, create-draft to manage drafts (type=Draft) — immature ideas of just a title and a note that live outside any status board and have no GitHub mirror; promote one with promote-draft, which hands off its content to /to-prd or /to-issues (the issue is born on GitHub, not by a local mutation) and removes the local draft. Statuses are inbox, backlog, todo, doing, done; priorities are urgent, high, medium, low. Stakeholder issues track external feedback. Projects have slugs and ids for identification. Timers are time entries linked to an activity — only one timer can run at a time. Documents are markdown pages (PRDs, specs, decisions, notes) that belong to projects. Use list-stakeholder-issues and promote-stakeholder-issue for feedback triage. Use get-project-context for full project overview.';

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        StartTimerTool::class,
        StopTimerTool::class,
        TimerStatusTool::class,
        TodayPlanTool::class,
        SuggestTasksTool::class,
        AddToPlanTool::class,
        ListProjectsTool::class,
        ListClientsTool::class,
        LogCommitsTool::class,
        GetAnalyticsTool::class,
        ListDocumentsTool::class,
        GetDocumentTool::class,
        CreateDocumentTool::class,
        UpdateDocumentTool::class,
        DeleteDocumentTool::class,
        GetProjectContextTool::class,
        ListTemplatesTool::class,
        ApplyTemplateTool::class,
        ListRecurringTasksTool::class,
        CreateRecurringTaskTool::class,
        ToggleRecurringTaskTool::class,
        ListEpicsTool::class,
        CreateEpicTool::class,
        UpdateEpicTool::class,
        ListIssuesTool::class,
        CreateIssueTool::class,
        UpdateIssueTool::class,
        DeleteIssueTool::class,
        ListTasksTool::class,
        CreateTaskTool::class,
        ListDraftsTool::class,
        CreateDraftTool::class,
        PromoteDraftTool::class,
        RalphExportTool::class,
        ListStakeholderIssuesTool::class,
        PromoteStakeholderIssueToFeatureTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        ProjectOverviewResource::class,
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        DailyPlanningPrompt::class,
        SessionPlanningPrompt::class,
        DevelopmentWorkflowPrompt::class,
        FeaturePlanningPrompt::class,
        StakeholderIssuePlanningPrompt::class,
    ];
}
