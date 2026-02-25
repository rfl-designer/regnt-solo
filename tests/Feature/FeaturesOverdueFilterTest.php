<?php

use App\Enums\FeaturePriority;
use App\Enums\TaskStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('Features Overdue Filter', function () {
    test('overdue filter property defaults to false', function () {
        Livewire::test('pages::features')
            ->assertSet('filterOverdue', false);
    });

    test('overdue filter shows only features with past due date', function () {
        // Overdue feature (has tasks so it's not Draft)
        $overdueFeature = Feature::factory()->create([
            'title' => 'Overdue Feature',
            'due_date' => now()->subDays(3),
        ]);
        Task::factory()->create([
            'feature_id' => $overdueFeature->id,
            'status' => TaskStatus::Todo,
        ]);

        // Future feature
        $futureFeature = Feature::factory()->create([
            'title' => 'Future Feature',
            'due_date' => now()->addDays(10),
        ]);
        Task::factory()->create([
            'feature_id' => $futureFeature->id,
            'status' => TaskStatus::Todo,
        ]);

        // Feature without due date
        $noDueFeature = Feature::factory()->create([
            'title' => 'No Due Feature',
        ]);
        Task::factory()->create([
            'feature_id' => $noDueFeature->id,
            'status' => TaskStatus::Backlog,
        ]);

        Livewire::test('pages::features')
            ->set('filterOverdue', true)
            ->assertSee('Overdue Feature')
            ->assertDontSee('Future Feature')
            ->assertDontSee('No Due Feature');
    });

    test('overdue filter excludes done features', function () {
        // Overdue but done feature
        $doneFeature = Feature::factory()->create([
            'title' => 'Done Overdue Feature',
            'due_date' => now()->subDays(5),
        ]);
        Task::factory()->create([
            'feature_id' => $doneFeature->id,
            'status' => TaskStatus::Done,
        ]);

        // Overdue and active feature
        $activeFeature = Feature::factory()->create([
            'title' => 'Active Overdue Feature',
            'due_date' => now()->subDays(2),
        ]);
        Task::factory()->create([
            'feature_id' => $activeFeature->id,
            'status' => TaskStatus::Doing,
        ]);

        Livewire::test('pages::features')
            ->set('filterOverdue', true)
            ->assertSee('Active Overdue Feature')
            ->assertDontSee('Done Overdue Feature');
    });

    test('overdue filter shows all features when disabled', function () {
        $overdueFeature = Feature::factory()->create([
            'title' => 'Overdue Feature Here',
            'due_date' => now()->subDays(3),
        ]);
        Task::factory()->create([
            'feature_id' => $overdueFeature->id,
            'status' => TaskStatus::Todo,
        ]);

        $futureFeature = Feature::factory()->create([
            'title' => 'Future Feature Here',
            'due_date' => now()->addDays(10),
        ]);
        Task::factory()->create([
            'feature_id' => $futureFeature->id,
            'status' => TaskStatus::Backlog,
        ]);

        Livewire::test('pages::features')
            ->set('filterOverdue', false)
            ->assertSee('Overdue Feature Here')
            ->assertSee('Future Feature Here');
    });

    test('overdue filter works with project filter (AND logic)', function () {
        $project = Project::factory()->create(['name' => 'Project Alpha']);

        $overdueInProject = Feature::factory()->create([
            'title' => 'Overdue In Project',
            'due_date' => now()->subDays(2),
            'project_id' => $project->id,
        ]);
        Task::factory()->create([
            'feature_id' => $overdueInProject->id,
            'status' => TaskStatus::Todo,
        ]);

        $overdueNoProject = Feature::factory()->create([
            'title' => 'Overdue No Project',
            'due_date' => now()->subDays(2),
        ]);
        Task::factory()->create([
            'feature_id' => $overdueNoProject->id,
            'status' => TaskStatus::Todo,
        ]);

        Livewire::test('pages::features')
            ->set('filterOverdue', true)
            ->set('filterProject', (string) $project->id)
            ->assertSee('Overdue In Project')
            ->assertDontSee('Overdue No Project');
    });

    test('overdue filter works with priority filter (AND logic)', function () {
        $overdueHigh = Feature::factory()->create([
            'title' => 'Overdue High Priority',
            'due_date' => now()->subDays(2),
            'priority' => FeaturePriority::High,
        ]);
        Task::factory()->create([
            'feature_id' => $overdueHigh->id,
            'status' => TaskStatus::Todo,
        ]);

        $overdueLow = Feature::factory()->create([
            'title' => 'Overdue Low Priority',
            'due_date' => now()->subDays(2),
            'priority' => FeaturePriority::Low,
        ]);
        Task::factory()->create([
            'feature_id' => $overdueLow->id,
            'status' => TaskStatus::Todo,
        ]);

        Livewire::test('pages::features')
            ->set('filterOverdue', true)
            ->set('filterPriority', FeaturePriority::High->value)
            ->assertSee('Overdue High Priority')
            ->assertDontSee('Overdue Low Priority');
    });

    test('overdue filter is persisted in URL', function () {
        Livewire::test('pages::features')
            ->set('filterOverdue', true)
            ->assertSet('filterOverdue', true);
    });

    test('overdue toggle button renders correctly', function () {
        Livewire::test('pages::features')
            ->assertSee('Vencidas');
    });
});
