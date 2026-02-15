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
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = 'SoloBoard is a personal productivity app for solo developers. It manages tasks, projects, time tracking, and daily planning. Use the available tools to create, read, update, and delete tasks; start and stop timers; manage daily plans; and list projects. Tasks have statuses (inbox, backlog, todo, doing, done) and priorities (urgent, high, medium, low). Projects have slugs for identification. Timers are time entries linked to tasks — only one timer can run at a time.';

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
    ];
}
