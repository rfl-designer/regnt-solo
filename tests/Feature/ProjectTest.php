<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;

test('can create a project with all fields', function () {
    $project = Project::factory()->create([
        'name' => 'Test Project',
        'slug' => 'test-project',
        'color' => '#ff0000',
        'emoji' => '🚀',
        'status' => ProjectStatus::Active,
        'priority' => ProjectPriority::High,
        'description' => 'A test project description',
    ]);

    expect($project)
        ->name->toBe('Test Project')
        ->slug->toBe('test-project')
        ->color->toBe('#ff0000')
        ->emoji->toBe('🚀')
        ->description->toBe('A test project description');

    $this->assertDatabaseHas('projects', [
        'name' => 'Test Project',
        'slug' => 'test-project',
    ]);
});

test('project has default values for color, emoji, status, and priority', function () {
    $project = Project::factory()->create([
        'color' => '#6366f1',
        'emoji' => '📋',
        'status' => ProjectStatus::Active,
        'priority' => ProjectPriority::Medium,
    ]);

    expect($project)
        ->color->toBe('#6366f1')
        ->emoji->toBe('📋')
        ->status->toBe(ProjectStatus::Active)
        ->priority->toBe(ProjectPriority::Medium);
});

test('status is cast to ProjectStatus enum', function () {
    $project = Project::factory()->active()->create();

    expect($project->status)
        ->toBeInstanceOf(ProjectStatus::class)
        ->toBe(ProjectStatus::Active);

    $project->update(['status' => ProjectStatus::Paused]);
    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Paused);
});

test('priority is cast to ProjectPriority enum', function () {
    $project = Project::factory()->highPriority()->create();

    expect($project->priority)
        ->toBeInstanceOf(ProjectPriority::class)
        ->toBe(ProjectPriority::High);

    $project->update(['priority' => ProjectPriority::Low]);
    $project->refresh();

    expect($project->priority)->toBe(ProjectPriority::Low);
});

test('active scope returns only active projects', function () {
    Project::factory()->active()->count(2)->create();
    Project::factory()->paused()->create();
    Project::factory()->archived()->create();

    $activeProjects = Project::active()->get();

    expect($activeProjects)->toHaveCount(2)
        ->each(fn ($project) => $project->status->toBe(ProjectStatus::Active));
});

test('paused scope returns only paused projects', function () {
    Project::factory()->active()->create();
    Project::factory()->paused()->count(3)->create();
    Project::factory()->archived()->create();

    $pausedProjects = Project::paused()->get();

    expect($pausedProjects)->toHaveCount(3)
        ->each(fn ($project) => $project->status->toBe(ProjectStatus::Paused));
});

test('archived scope returns only archived projects', function () {
    Project::factory()->active()->create();
    Project::factory()->paused()->create();
    Project::factory()->archived()->count(2)->create();

    $archivedProjects = Project::archived()->get();

    expect($archivedProjects)->toHaveCount(2)
        ->each(fn ($project) => $project->status->toBe(ProjectStatus::Archived));
});

test('ordered scope sorts by priority (high > medium > low) then by name', function () {
    Project::factory()->lowPriority()->create(['name' => 'Zeta']);
    Project::factory()->highPriority()->create(['name' => 'Beta']);
    Project::factory()->mediumPriority()->create(['name' => 'Alpha']);
    Project::factory()->highPriority()->create(['name' => 'Alpha']);
    Project::factory()->lowPriority()->create(['name' => 'Alpha']);

    $projects = Project::ordered()->get();

    expect($projects[0])->name->toBe('Alpha')->priority->toBe(ProjectPriority::High);
    expect($projects[1])->name->toBe('Beta')->priority->toBe(ProjectPriority::High);
    expect($projects[2])->name->toBe('Alpha')->priority->toBe(ProjectPriority::Medium);
    expect($projects[3])->name->toBe('Alpha')->priority->toBe(ProjectPriority::Low);
    expect($projects[4])->name->toBe('Zeta')->priority->toBe(ProjectPriority::Low);
});

test('slug must be unique', function () {
    Project::factory()->create(['slug' => 'unique-slug']);

    expect(fn () => Project::factory()->create(['slug' => 'unique-slug']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('description is nullable', function () {
    $project = Project::factory()->create(['description' => null]);

    expect($project->description)->toBeNull();
});

test('project status enum has correct labels', function () {
    expect(ProjectStatus::Active->label())->toBe('Ativo');
    expect(ProjectStatus::Paused->label())->toBe('Pausado');
    expect(ProjectStatus::Archived->label())->toBe('Arquivado');
});

test('project status enum has correct colors', function () {
    expect(ProjectStatus::Active->color())->toBe('green');
    expect(ProjectStatus::Paused->color())->toBe('amber');
    expect(ProjectStatus::Archived->color())->toBe('zinc');
});

test('project status enum has correct icons', function () {
    expect(ProjectStatus::Active->icon())->toBe('check-circle');
    expect(ProjectStatus::Paused->icon())->toBe('pause-circle');
    expect(ProjectStatus::Archived->icon())->toBe('archive-box');
});

test('project priority enum has correct labels', function () {
    expect(ProjectPriority::High->label())->toBe('Alta');
    expect(ProjectPriority::Medium->label())->toBe('Média');
    expect(ProjectPriority::Low->label())->toBe('Baixa');
});

test('project priority enum has correct colors', function () {
    expect(ProjectPriority::High->color())->toBe('red');
    expect(ProjectPriority::Medium->color())->toBe('amber');
    expect(ProjectPriority::Low->color())->toBe('sky');
});

test('project priority enum has correct icons', function () {
    expect(ProjectPriority::High->icon())->toBe('arrow-up-circle');
    expect(ProjectPriority::Medium->icon())->toBe('minus-circle');
    expect(ProjectPriority::Low->icon())->toBe('arrow-down-circle');
});
