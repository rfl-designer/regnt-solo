<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Task;

test('can create a project', function () {
    $project = Project::factory()->create([
        'name' => 'Meu Projeto',
        'slug' => 'meu-projeto',
    ]);

    expect($project)
        ->name->toBe('Meu Projeto')
        ->slug->toBe('meu-projeto')
        ->status->toBe(ProjectStatus::Active)
        ->priority->toBe(ProjectPriority::Medium);
});

test('project casts status and priority to enums', function () {
    $project = Project::factory()->create();

    expect($project->status)->toBeInstanceOf(ProjectStatus::class)
        ->and($project->priority)->toBeInstanceOf(ProjectPriority::class);
});

test('project has many tasks', function () {
    $project = Project::factory()->create();
    Task::factory()->count(3)->create(['project_id' => $project->id]);

    expect($project->tasks)->toHaveCount(3);
});

test('active scope returns only active projects', function () {
    Project::factory()->create();
    Project::factory()->paused()->create();
    Project::factory()->archived()->create();

    $active = Project::query()->active()->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->status)->toBe(ProjectStatus::Active);
});

test('paused scope returns only paused projects', function () {
    Project::factory()->create();
    Project::factory()->paused()->create();

    $paused = Project::query()->paused()->get();

    expect($paused)->toHaveCount(1)
        ->and($paused->first()->status)->toBe(ProjectStatus::Paused);
});

test('archived scope returns only archived projects', function () {
    Project::factory()->create();
    Project::factory()->archived()->create();

    $archived = Project::query()->archived()->get();

    expect($archived)->toHaveCount(1)
        ->and($archived->first()->status)->toBe(ProjectStatus::Archived);
});

test('ordered scope sorts by priority desc then name asc', function () {
    Project::factory()->create(['name' => 'Zebra', 'priority' => ProjectPriority::Low]);
    Project::factory()->create(['name' => 'Bravo', 'priority' => ProjectPriority::High]);
    Project::factory()->create(['name' => 'Alpha', 'priority' => ProjectPriority::High]);
    Project::factory()->create(['name' => 'Charlie', 'priority' => ProjectPriority::Medium]);

    $ordered = Project::query()->ordered()->pluck('name')->all();

    expect($ordered)->toBe(['Alpha', 'Bravo', 'Charlie', 'Zebra']);
});
