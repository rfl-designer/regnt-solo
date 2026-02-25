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
    protected string $instructions = 'SoloBoard is a personal productivity app for solo developers. It manages tasks, features, projects, time tracking, and daily planning. Use the available tools to create, read, update, and delete tasks and features; start and stop timers; manage daily plans; and list projects. Tasks have statuses (inbox, backlog, todo, doing, done) and priorities (urgent, high, medium, low). Features group related tasks and have computed status based on their tasks (draft, backlog, todo, doing, done). Projects have slugs for identification. Timers are time entries linked to tasks or features — only one timer can run at a time. Documents are markdown pages (PRDs, specs, decisions, notes) that belong to projects. Use list-features, get-feature, create-feature, update-feature, delete-feature, add-task-to-feature to manage features. Use get-project-context for full project overview.';

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        \App\Mcp\Tools\ListTasksTool::class,
        \App\Mcp\Tools\GetTaskTool::class,
        \App\Mcp\Tools\CreateTaskTool::class,
        \App\Mcp\Tools\UpdateTaskTool::class,
        \App\Mcp\Tools\DeleteTaskTool::class,
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
        \App\Mcp\Tools\ListFeaturesTool::class,
        \App\Mcp\Tools\GetFeatureTool::class,
        \App\Mcp\Tools\CreateFeatureTool::class,
        \App\Mcp\Tools\UpdateFeatureTool::class,
        \App\Mcp\Tools\DeleteFeatureTool::class,
        \App\Mcp\Tools\AddTaskToFeatureTool::class,
        \App\Mcp\Tools\RalphExportTool::class,
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
    ];
}
