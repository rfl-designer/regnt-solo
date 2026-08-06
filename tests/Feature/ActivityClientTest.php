<?php

use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;

test('effective client resolves to the project client when activity has a project', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $activity = Activity::factory()->create(['project_id' => $project->id]);

    expect($activity->effective_client)
        ->not->toBeNull()
        ->id->toBe($client->id);
});

test('effective client resolves to the direct client when activity has no project', function () {
    $client = Client::factory()->create();
    $activity = Activity::factory()->create(['project_id' => null, 'client_id' => $client->id]);

    expect($activity->effective_client)
        ->not->toBeNull()
        ->id->toBe($client->id);
});

test('effective client is null when there is no project and no direct client', function () {
    $activity = Activity::factory()->create(['project_id' => null, 'client_id' => null]);

    expect($activity->effective_client)->toBeNull();
});

test('effective client is null when activity has a project without a client', function () {
    $project = Project::factory()->create(['client_id' => null]);
    $activity = Activity::factory()->create(['project_id' => $project->id]);

    expect($activity->effective_client)->toBeNull();
});

test('setting a project on an activity clears its direct client link', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create();
    $activity = Activity::factory()->create(['project_id' => null, 'client_id' => $client->id]);

    $activity->update(['project_id' => $project->id]);

    expect($activity->fresh())
        ->project_id->toBe($project->id)
        ->client_id->toBeNull();
});

test('an activity created directly with both project and client keeps only the project link', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create();

    $activity = Activity::create([
        'type' => 'task',
        'title' => 'Task com projeto e cliente',
        'status' => 'inbox',
        'priority' => 'medium',
        'project_id' => $project->id,
        'client_id' => $client->id,
    ]);

    expect($activity->fresh())
        ->project_id->toBe($project->id)
        ->client_id->toBeNull();
});
