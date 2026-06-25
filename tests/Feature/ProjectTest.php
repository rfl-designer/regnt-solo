<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Project;

test('project status enum has all expected values', function () {
    $cases = ProjectStatus::cases();

    expect($cases)->toHaveCount(6)
        ->and(array_column($cases, 'value'))->toBe([
            'backlog',
            'active',
            'paused',
            'done',
            'maintenance',
            'archived',
        ]);
});

test('project status enum has labels in PT-BR', function () {
    expect(ProjectStatus::Backlog->label())->toBe('Backlog')
        ->and(ProjectStatus::Active->label())->toBe('Ativo')
        ->and(ProjectStatus::Paused->label())->toBe('Pausado')
        ->and(ProjectStatus::Done->label())->toBe('Concluído')
        ->and(ProjectStatus::Maintenance->label())->toBe('Manutenção')
        ->and(ProjectStatus::Archived->label())->toBe('Arquivado');
});

test('project status enum has colors', function () {
    expect(ProjectStatus::Backlog->color())->toBe('zinc')
        ->and(ProjectStatus::Active->color())->toBe('emerald')
        ->and(ProjectStatus::Paused->color())->toBe('amber')
        ->and(ProjectStatus::Done->color())->toBe('sky')
        ->and(ProjectStatus::Maintenance->color())->toBe('violet')
        ->and(ProjectStatus::Archived->color())->toBe('zinc');
});

test('project status enum has icons', function () {
    expect(ProjectStatus::Backlog->icon())->toBe('inbox-stack')
        ->and(ProjectStatus::Active->icon())->toBe('play-circle')
        ->and(ProjectStatus::Paused->icon())->toBe('pause-circle')
        ->and(ProjectStatus::Done->icon())->toBe('check-circle')
        ->and(ProjectStatus::Maintenance->icon())->toBe('wrench-screwdriver')
        ->and(ProjectStatus::Archived->icon())->toBe('archive-box');
});

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
    Activity::factory()->count(3)->create(['project_id' => $project->id]);

    expect($project->tasks)->toHaveCount(3);
});

test('backlog scope returns only backlog projects', function () {
    Project::factory()->backlog()->create();
    Project::factory()->create();
    Project::factory()->paused()->create();

    $backlog = Project::query()->backlog()->get();

    expect($backlog)->toHaveCount(1)
        ->and($backlog->first()->status)->toBe(ProjectStatus::Backlog);
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

test('done scope returns only done projects', function () {
    Project::factory()->create();
    Project::factory()->done()->create();

    $done = Project::query()->done()->get();

    expect($done)->toHaveCount(1)
        ->and($done->first()->status)->toBe(ProjectStatus::Done);
});

test('maintenance scope returns only maintenance projects', function () {
    Project::factory()->create();
    Project::factory()->maintenance()->create();

    $maintenance = Project::query()->maintenance()->get();

    expect($maintenance)->toHaveCount(1)
        ->and($maintenance->first()->status)->toBe(ProjectStatus::Maintenance);
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
    Project::factory()->create(['name' => 'Delta', 'priority' => ProjectPriority::Urgent]);
    Project::factory()->create(['name' => 'Echo', 'priority' => ProjectPriority::Urgent]);

    $ordered = Project::query()->ordered()->pluck('name')->all();

    expect($ordered)->toBe(['Delta', 'Echo', 'Alpha', 'Bravo', 'Charlie', 'Zebra']);
});

test('project priority enum has all expected values', function () {
    $cases = ProjectPriority::cases();

    expect($cases)->toHaveCount(4)
        ->and(array_column($cases, 'value'))->toBe([
            'urgent',
            'high',
            'medium',
            'low',
        ]);
});

test('project priority enum has labels in PT-BR', function () {
    expect(ProjectPriority::Urgent->label())->toBe('Urgente')
        ->and(ProjectPriority::High->label())->toBe('Alta')
        ->and(ProjectPriority::Medium->label())->toBe('Média')
        ->and(ProjectPriority::Low->label())->toBe('Baixa');
});

test('project priority enum has colors', function () {
    expect(ProjectPriority::Urgent->color())->toBe('red')
        ->and(ProjectPriority::High->color())->toBe('orange')
        ->and(ProjectPriority::Medium->color())->toBe('blue')
        ->and(ProjectPriority::Low->color())->toBe('zinc');
});

test('project priority enum has icons', function () {
    expect(ProjectPriority::Urgent->icon())->toBe('fire')
        ->and(ProjectPriority::High->icon())->toBe('arrow-up')
        ->and(ProjectPriority::Medium->icon())->toBe('minus')
        ->and(ProjectPriority::Low->icon())->toBe('arrow-down');
});
