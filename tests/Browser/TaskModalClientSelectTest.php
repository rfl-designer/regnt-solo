<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * The client select in the task modal is only meaningful for tasks without a
 * project — with a project, the client is inherited and the select is locked.
 */
$clientSelect = '[role="tabpanel"] select[wire\\:model="clientId"]';

test('client select is disabled when the task has a project', function () use ($clientSelect): void {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);

    Activity::factory()->issue()->create([
        'title' => 'Task com projeto',
        'status' => ActivityStatus::Todo,
        'project_id' => $project->id,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Task com projeto')
        ->waitForText('Herdado do projeto')
        ->assertPresent($clientSelect)
        ->assertAttribute($clientSelect, 'disabled', 'disabled')
        ->assertSee('Herdado do projeto');
});

test('client select is enabled when the task has no project', function () use ($clientSelect): void {
    $client = Client::factory()->create(['name' => 'Globex Inc']);

    Activity::factory()->issue()->create([
        'title' => 'Task sem projeto',
        'status' => ActivityStatus::Todo,
        'project_id' => null,
        'client_id' => $client->id,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Task sem projeto')
        ->waitForText('Detalhes')
        ->assertPresent($clientSelect)
        ->assertAttributeMissing($clientSelect, 'disabled')
        ->assertDontSee('Herdado do projeto');
});
