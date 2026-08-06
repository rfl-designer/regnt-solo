<?php

use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\ListClientsTool;
use App\Models\Client;

// ListClientsTool tests
test('list-clients returns active clients by default', function () {
    $active = Client::factory()->create(['name' => 'Active Co']);
    $archived = Client::factory()->archived()->create(['name' => 'Archived Co']);

    $response = SoloBoardServer::tool(ListClientsTool::class, []);

    $response->assertOk();
    $response->assertSee('Active Co');
    $response->assertDontSee('Archived Co');
});

test('list-clients includes archived clients when requested', function () {
    $archived = Client::factory()->archived()->create(['name' => 'Archived Co']);

    $response = SoloBoardServer::tool(ListClientsTool::class, [
        'include_archived' => true,
    ]);

    $response->assertOk();
    $response->assertSee('Archived Co');
});

test('list-clients returns clients that have no project', function () {
    $client = Client::factory()->create(['name' => 'No Project Co']);

    $response = SoloBoardServer::tool(ListClientsTool::class, []);

    $response->assertOk();
    $response->assertSee('No Project Co');
    $response->assertSee((string) $client->id);
});

test('list-clients returns id, name, slug, color and is_active', function () {
    Client::factory()->create([
        'name' => 'Full Fields Co',
        'slug' => 'full-fields-co',
        'color' => '#ff0000',
    ]);

    $response = SoloBoardServer::tool(ListClientsTool::class, []);

    $response->assertOk();
    $response->assertSee('"name": "Full Fields Co"');
    $response->assertSee('"slug": "full-fields-co"');
    $response->assertSee('"color": "#ff0000"');
    $response->assertSee('"is_active": true');
});

// Integration: list-clients -> create-task
test('a client id discovered via list-clients can be used to create a task', function () {
    $client = Client::factory()->create(['name' => 'Discoverable Co']);

    $listResponse = SoloBoardServer::tool(ListClientsTool::class, []);
    $listResponse->assertOk();
    $listResponse->assertSee((string) $client->id);

    $createResponse = SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Task For Discoverable Client',
        'client_id' => $client->id,
    ]);

    $createResponse->assertOk();
    $createResponse->assertSee('"client": "Discoverable Co"');

    $this->assertDatabaseHas('activities', [
        'title' => 'Task For Discoverable Client',
        'client_id' => $client->id,
        'project_id' => null,
    ]);
});
