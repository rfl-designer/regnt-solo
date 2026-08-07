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

    /*
    |--------------------------------------------------------------------------
    | "Data fixa em risco" window, in days (issue #144)
    |--------------------------------------------------------------------------
    |
    | How close a Data fixa has to be to its due date before the pull queue
    | promotes it above the FIFO degrau. An item is at risk when it has N
    | days or fewer left — an overdue one is, of course, still at risk.
    |
    | This N is a stand-in, not the final answer: once the board has enough
    | history for a usable SLE (Service Level Expectation), the queue will
    | derive the window from the measured lead time and this constant stops
    | being consulted. That swap belongs to the metrics slice; until it
    | lands, N is the honest approximation.
    |
    | Config-only for the same reason as the WIP limit above: it is part of
    | the method, not a preference to toggle from the UI.
    |
    */
    'fixed_date_risk_days' => (int) env('SOLOBOARD_FIXED_DATE_RISK_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | SLE — Service Level Expectation (issue #145)
    |--------------------------------------------------------------------------
    |
    | The board's promise, measured rather than declared: "N% of the items
    | finish in Y days or less". Y is not configured — it is the percentile
    | of the cycle times observed since the last baseline cut. What *is*
    | configured is the method behind it:
    |
    | - `sle_percentile`: which percentile answers the promise. 85 is the
    |   usual starting point — high enough to be a commitment, low enough
    |   not to be dominated by the one item that sat forgotten for a month.
    | - `sle_minimum_sample`: how many measured items the percentile needs
    |   before anyone is allowed to quote it. Below this the surfaces say
    |   "amostra pequena (n=X)" and the pull queue keeps using the N-day
    |   window — a percentile over 6 items is a number, not a promise.
    | - `sle_attention_percent`: how far into the SLE an item gets before
    |   the board flags it. At 80% the card turns amber; at 100% it turns
    |   red, which is the SLE being broken, not approached.
    |
    | Config-only, like the WIP limit and the risk window: these are the
    | method's dials, and moving them should be a reviewed act rather than
    | a click. There is deliberately no UI to edit them.
    |
    */
    'sle_percentile' => (int) env('SOLOBOARD_SLE_PERCENTILE', 85),
    'sle_minimum_sample' => (int) env('SOLOBOARD_SLE_MINIMUM_SAMPLE', 30),
    'sle_attention_percent' => (int) env('SOLOBOARD_SLE_ATTENTION_PERCENT', 80),
];
