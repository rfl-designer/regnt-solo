<?php

test('mcp request without api key returns 401', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'id' => 1,
    ]);

    $response->assertUnauthorized();
});

test('mcp request with invalid api key returns 401', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'id' => 1,
    ], [
        'Authorization' => 'Bearer wrong-key',
    ]);

    $response->assertUnauthorized();
});

test('mcp request with valid api key returns 200', function () {
    config(['soloboard.mcp_key' => 'soloboard-mcp-secret']);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'id' => 1,
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass,
            'clientInfo' => [
                'name' => 'test-client',
                'version' => '1.0.0',
            ],
        ],
    ], [
        'Authorization' => 'Bearer soloboard-mcp-secret',
    ]);

    $response->assertSuccessful();
});

test('mcp request with empty configured key returns 403', function () {
    config(['soloboard.mcp_key' => null]);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'initialize',
        'id' => 1,
    ], [
        'Authorization' => 'Bearer soloboard-mcp-secret',
    ]);

    $response->assertForbidden();
});
