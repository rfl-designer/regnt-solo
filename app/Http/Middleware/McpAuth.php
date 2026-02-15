<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class McpAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = config('soloboard.mcp_key');

        if (empty($key)) {
            abort(403, 'MCP API key not configured.');
        }

        $token = $request->bearerToken();

        if ($token !== $key) {
            abort(401, 'Invalid MCP API key.');
        }

        return $next($request);
    }
}
