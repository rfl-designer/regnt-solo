<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;

test('client related pages load without javascript errors', function (): void {
    $this->actingAs(User::factory()->create());

    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);

    Activity::factory()->issue()->create([
        'title' => 'Task com projeto',
        'status' => ActivityStatus::Todo,
        'project_id' => $project->id,
    ]);

    Activity::factory()->issue()->create([
        'title' => 'Task sem projeto',
        'status' => ActivityStatus::Doing,
        'project_id' => null,
        'client_id' => $client->id,
    ]);

    visit(['/clients', '/kanban', '/flow'])
        ->assertNoJavaScriptErrors()
        ->assertNoBrokenImages();
});
