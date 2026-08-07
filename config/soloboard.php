<?php

return [
    'mcp_key' => env('SOLOBOARD_MCP_KEY'),

    'ai_enabled' => env('SOLOBOARD_AI_ENABLED', false),
    'ai_api_key' => env('ANTHROPIC_API_KEY'),
    'ai_model' => env('SOLOBOARD_AI_MODEL', 'claude-sonnet-4-20250514'),
    'ai_insights_cache_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | WIP limit for "Fazendo" (issue #143)
    |--------------------------------------------------------------------------
    |
    | Hard cap on how many board items (issues and atomic epics) may sit in
    | the Doing column at the same time. Enforced by ActivityObserver at the
    | Eloquent seam, so every origin — Kanban, Task Modal, MCP tools, tinker
    | — gets the same refusal. An item classified as Emergência is the single
    | documented exception: it always gets in, which is why the board can
    | legitimately read "3/2".
    |
    | Deliberately config-only: there is no UI to edit it. Changing the
    | method's core constraint should be a conscious, reviewed act.
    |
    */
    'wip_limit_doing' => (int) env('SOLOBOARD_WIP_LIMIT_DOING', 2),
];
