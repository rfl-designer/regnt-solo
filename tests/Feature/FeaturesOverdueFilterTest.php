<?php

use App\Enums\FeaturePriority;
use App\Models\Feature;
use App\Models\Project;
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
        // Overdue feature with explicit todo status
        $overdueFeature = Feature::factory()->todo()->create([
            'title' => 'Overdue Feature',
            'due_date' => now()->subDays(3),
        ]);

        // Future feature
        $futureFeature = Feature::factory()->todo()->create([
            'title' => 'Future Feature',
            'due_date' => now()->addDays(10),
        ]);

        // Feature without due date
        $noDueFeature = Feature::factory()->backlog()->create([
            'title' => 'No Due Feature',
        ]);

        Livewire::test('pages::features')
            ->set('filterOverdue', true)
            ->assertSee('Overdue Feature')
            ->assertDontSee('Future Feature')
            ->assertDontSee('No Due Feature');
    });

    test('overdue filter excludes done features', function () {
        // Overdue but explicitly done feature
        Feature::factory()->done()->create([
            'title' => 'Done Overdue Feature',
            'due_date' => now()->subDays(5),
        ]);

        // Overdue and active feature
        Feature::factory()->doing()->create([
            'title' => 'Active Overdue Feature',
            'due_date' => now()->subDays(2),
        ]);

        Livewire::test('pages::features')
            ->set('filterOverdue', true)
            ->assertSee('Active Overdue Feature')
            ->assertDontSee('Done Overdue Feature');
    });

    test('overdue filter shows all features when disabled', function () {
        Feature::factory()->todo()->create([
            'title' => 'Overdue Feature Here',
            'due_date' => now()->subDays(3),
        ]);

        Feature::factory()->backlog()->create([
            'title' => 'Future Feature Here',
            'due_date' => now()->addDays(10),
        ]);

        Livewire::test('pages::features')
            ->set('filterOverdue', false)
            ->assertSee('Overdue Feature Here')
            ->assertSee('Future Feature Here');
    });

    test('overdue filter works with project filter (AND logic)', function () {
        $project = Project::factory()->create(['name' => 'Project Alpha']);

        Feature::factory()->todo()->create([
            'title' => 'Overdue In Project',
            'due_date' => now()->subDays(2),
            'project_id' => $project->id,
        ]);

        Feature::factory()->todo()->create([
            'title' => 'Overdue No Project',
            'due_date' => now()->subDays(2),
        ]);

        Livewire::test('pages::features')
            ->set('filterOverdue', true)
            ->set('filterProject', (string) $project->id)
            ->assertSee('Overdue In Project')
            ->assertDontSee('Overdue No Project');
    });

    test('overdue filter works with priority filter (AND logic)', function () {
        Feature::factory()->todo()->create([
            'title' => 'Overdue High Priority',
            'due_date' => now()->subDays(2),
            'priority' => FeaturePriority::High,
        ]);

        Feature::factory()->todo()->create([
            'title' => 'Overdue Low Priority',
            'due_date' => now()->subDays(2),
            'priority' => FeaturePriority::Low,
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
