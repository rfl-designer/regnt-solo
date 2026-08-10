<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DevelopmentWorkflowPrompt;
use App\Mcp\Prompts\FeaturePlanningPrompt;
use App\Mcp\Prompts\SessionPlanningPrompt;
use App\Mcp\Prompts\StakeholderIssuePlanningPrompt;
use App\Mcp\Resources\ProjectOverviewResource;
use App\Mcp\Tools\ApplyTemplateTool;
use App\Mcp\Tools\CreateDocumentTool;
use App\Mcp\Tools\CreateDraftTool;
use App\Mcp\Tools\CreateEpicTool;
use App\Mcp\Tools\CreateIssueTool;
use App\Mcp\Tools\CreateRecurringTaskTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\DeleteDocumentTool;
use App\Mcp\Tools\DeleteIssueTool;
use App\Mcp\Tools\GenerateClientUpdateTool;
use App\Mcp\Tools\GetAnalyticsTool;
use App\Mcp\Tools\GetDocumentTool;
use App\Mcp\Tools\GetPitchTool;
use App\Mcp\Tools\GetProjectContextTool;
use App\Mcp\Tools\GetPullQueueTool;
use App\Mcp\Tools\GetRitualStatusTool;
use App\Mcp\Tools\GetUpdateQueueTool;
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
use App\Mcp\Tools\MarkUpdateSentTool;
use App\Mcp\Tools\PromoteDraftTool;
use App\Mcp\Tools\PromoteStakeholderIssueToFeatureTool;
use App\Mcp\Tools\RalphExportTool;
use App\Mcp\Tools\StartTimerTool;
use App\Mcp\Tools\StopTimerTool;
use App\Mcp\Tools\TimerStatusTool;
use App\Mcp\Tools\ToggleRecurringTaskTool;
use App\Mcp\Tools\UpdateDocumentTool;
use App\Mcp\Tools\UpdateEpicTool;
use App\Mcp\Tools\UpdateIssueTool;
use App\Mcp\Tools\UpdateTaskTool;
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
    protected string $instructions = 'SoloBoard is a personal productivity app for solo developers. It manages a roadmap mirrored one-way from GitHub plus a personal layer, projects, stakeholder issues, time tracking, and the morning ritual. The roadmap has two types: Epics (top-level, mirror GitHub type:prd issues) and Issues (mirror other GitHub issues; their client-facing label Fatia/Follow-up/Avulsa is derived from the parent). Use list-epics, create-epic, update-epic to manage epics; the epic status is manual (create-epic never sets it). Use list-issues, create-issue, update-issue, delete-issue to manage issues; create/update accept project_id, parent_id and status, and setting status to done marks the issue done. Both epics and issues upsert by github_issue_number for idempotent syncs; delete-issue cascades time entries and is used for reconciliation. Use list-drafts, create-draft to manage drafts (type=Draft) — immature ideas of just a title and a note that live outside any status board and have no GitHub mirror; a draft is shaped (Dor = description, Apetite = appetite_days in calendar days, Esboço = spec, plus rabbit_holes and no_gos) and then promoted with promote-draft, which turns that very record into an Epic in backlog with an empty Spec lifecycle — nothing is created on GitHub, and promote-draft refuses with the same message the UI shows when Dor, Apetite, Esboço or the project is missing. Use get-pitch to render a draft or epic as the deterministic five-section markdown pitch. Statuses are inbox, backlog, awaiting_approval, todo, doing, waiting, awaiting_validation, done (the 7-column board, in flow order, excludes inbox); priorities are urgent, high, medium, low. awaiting_approval, waiting and awaiting_validation are the three waiting statuses (issue #142): moving into one requires waiting_for ("esperando quem") — client-side waits (awaiting_approval/awaiting_validation) auto-fill it from the activity\'s effective client when omitted, the internal wait (waiting) has no default and is refused without an explicit value. waiting_since is stamped automatically and cleared automatically on leaving a wait. Stakeholder issues track external feedback. Projects have slugs and ids for identification. Use get-pull-queue to answer \'what do I pull next?\': it returns the Pronto (todo) column in pull order — Emergência, then Data fixa at risk by due date, then FIFO by last entry into Pronto — with the motivo of each position and the Fazendo WIP/Emergência context; it never recommends, the decision stays with the user. That same context block carries the intangible hunger (issue #153): the days since an item classified as Intangível last entered Feito, against the configured threshold of intangible_starvation_days (default 14). Pulling an Intangível without concluding it never resets the clock — only a conclusion does — and a board with no Intangível conclusion in its history anchors the clock on the current baseline cut and reports starving regardless of the threshold — even on a cut made today — because "never concluded one" is the hungriest the board can be, not missing data, and the threshold only ages a real conclusion. An empty pantry does not silence it: when ready_in_pronto is zero the alert still fires and the remedy changes from pulling an Intangível to creating or promoting one. Timers are time entries linked to an activity — only one timer can run at a time. Documents are markdown pages (PRDs, specs, decisions, notes) that belong to projects. Use list-stakeholder-issues and promote-stakeholder-issue for feedback triage. Use get-ritual-status to read whether today\'s morning ritual has been done and the snapshot of the board it walks (archive backlog, the three waiting columns, Fazendo vs the WIP limit, aging, the pull queue) — it is read-only; there is no daily plan to read, add to or be suggested for, the board is the list. Use get-update-queue, generate-client-update and mark-update-sent for the weekly client update (issues #149 and #150): the queue orders active clients by urgency, which is event first and then the cadence degrees overdue, due today and on track — urgency is the category and cadence is always the clock degree, published side by side, so a client promoted by an event can still be overdue by the clock. A client is in event when its triggers array is not empty, meaning the board produced news that does not wait for the cadence: a spec of that client entered awaiting_validation within the window, or an Emergência of any item of that client (not only spec level) was classified within the window and is still active, or was concluded within the window with a motivo. Triggers are derived on every read and never stored, so sending is what clears them — sending opens a new window — while an event already resolved keeps counting until the next send; due_count and only_due include the clients that are due by event alone. Generating writes the same persisted draft the Updates page writes, in four PT-BR blocks (Entregue / Em andamento / Esperando você / Próximo) filtered by effective client and spec level with empty blocks omitted, covering from the last send with no cap (the first update covers 7 days); marking as sent requires the id of an existing draft and is what resets the cadence clock — generating and copying never send. Use get-project-context for full project overview. Both list-epics and get-project-context publish the apetite guard (issue #152): the apetite is the calendar-day budget chosen in shaping, and its consumption is the calendar days from the spec approval to the validation, or to now while the bet is still live — the same window the flow efficiency is measured in, derived from the status history and stored nowhere. The status is ok, warning at 80% of the budget, exceeded at 100% (the same two thresholds the aging uses against the SLE), no_appetite when no budget was ever chosen, and null in both tools while the spec has never been approved — there is no bet running to measure, and the two seams publish the very same four statuses. An epic with no apetite is never flagged: the guard stays silent instead of inventing a budget.';

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        StartTimerTool::class,
        StopTimerTool::class,
        TimerStatusTool::class,
        GetPullQueueTool::class,
        GetRitualStatusTool::class,
        GetUpdateQueueTool::class,
        GenerateClientUpdateTool::class,
        MarkUpdateSentTool::class,
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
        UpdateTaskTool::class,
        ListDraftsTool::class,
        CreateDraftTool::class,
        PromoteDraftTool::class,
        GetPitchTool::class,
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
        SessionPlanningPrompt::class,
        DevelopmentWorkflowPrompt::class,
        FeaturePlanningPrompt::class,
        StakeholderIssuePlanningPrompt::class,
    ];
}
