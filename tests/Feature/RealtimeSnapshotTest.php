<?php

use App\Models\Document;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\StakeholderIssue;
use App\Models\Task;

test('realtime snapshot route returns tracked entity counters', function () {
    $response = $this->getJson(route('realtime.snapshot'))
        ->assertSuccessful();

    $snapshot = $response->json();

    expect($snapshot)->toHaveKeys(['project', 'feature', 'task', 'document', 'issue']);
    expect($snapshot['project'])->toBeInt();
    expect($snapshot['feature'])->toBeInt();
    expect($snapshot['task'])->toBeInt();
    expect($snapshot['document'])->toBeInt();
    expect($snapshot['issue'])->toBeInt();
});

test('realtime snapshot counters are incremented when tracked entities change', function () {
    $initialSnapshot = $this->getJson(route('realtime.snapshot'))
        ->assertSuccessful()
        ->json();

    $project = Project::factory()->create();
    $feature = Feature::factory()->create(['project_id' => $project->id]);
    Task::factory()->create([
        'project_id' => $project->id,
        'feature_id' => $feature->id,
    ]);
    Document::factory()->create(['project_id' => $project->id]);

    $stakeholder = Stakeholder::factory()->create(['project_id' => $project->id]);
    StakeholderIssue::factory()->create([
        'project_id' => $project->id,
        'stakeholder_id' => $stakeholder->id,
    ]);

    $updatedSnapshot = $this->getJson(route('realtime.snapshot'))
        ->assertSuccessful()
        ->json();

    expect($updatedSnapshot['project'])->toBeGreaterThan($initialSnapshot['project']);
    expect($updatedSnapshot['feature'])->toBeGreaterThan($initialSnapshot['feature']);
    expect($updatedSnapshot['task'])->toBeGreaterThan($initialSnapshot['task']);
    expect($updatedSnapshot['document'])->toBeGreaterThan($initialSnapshot['document']);
    expect($updatedSnapshot['issue'])->toBeGreaterThan($initialSnapshot['issue']);
});
