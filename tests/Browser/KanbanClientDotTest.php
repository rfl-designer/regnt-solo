<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('kanban card shows the client dot when the task inherits a client from its project', function (): void {
    $client = Client::factory()->create(['name' => 'Acme Corp', 'color' => '#ff00ff']);
    $project = Project::factory()->create(['client_id' => $client->id]);

    Activity::factory()->issue()->create([
        'title' => 'Task com projeto',
        'status' => ActivityStatus::Todo,
        'project_id' => $project->id,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Task com projeto')
        ->assertPresent('div[style*="background-color: #ff00ff"]');
});

test('kanban card shows the client dot when the task links a client directly', function (): void {
    $client = Client::factory()->create(['name' => 'Globex Inc', 'color' => '#00ffaa']);

    Activity::factory()->issue()->create([
        'title' => 'Task sem projeto',
        'status' => ActivityStatus::Todo,
        'project_id' => null,
        'client_id' => $client->id,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Task sem projeto')
        ->assertPresent('div[style*="background-color: #00ffaa"]');
});

test('kanban card shows no client dot when the task has no effective client', function (): void {
    Client::factory()->create(['name' => 'Cliente Solto', 'color' => '#123456']);

    Activity::factory()->issue()->create([
        'title' => 'Task orfa',
        'status' => ActivityStatus::Todo,
        'project_id' => null,
        'client_id' => null,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Task orfa')
        ->assertNotPresent('div[style*="background-color: #123456"]');
});
