<?php

return [
    'mcp_key' => env('SOLOBOARD_MCP_KEY'),

    'ai_enabled' => env('SOLOBOARD_AI_ENABLED', false),
    'ai_api_key' => env('ANTHROPIC_API_KEY'),
    'ai_model' => env('SOLOBOARD_AI_MODEL', 'claude-sonnet-4-20250514'),
    'ai_insights_cache_hours' => 24,

    // Terminal Server (Claude Code integration)
    'terminal_server_url' => env('TERMINAL_SERVER_URL', 'ws://localhost:3001'),
    'terminal_server_port' => env('TERMINAL_SERVER_PORT', 3001),
];
