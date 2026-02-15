<?php

use App\Mcp\Servers\SoloBoardServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', SoloBoardServer::class)
    ->middleware(\App\Http\Middleware\McpAuth::class);
