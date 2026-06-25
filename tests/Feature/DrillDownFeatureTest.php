<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// ========================================
// "Ver Tasks" button on feature card
// ========================================

describe('Ver Tasks button', function () {
    test('does not appear on feature card when feature has zero tasks', function () {
        $project = Project::factory()->create();

        // ADR: Draft status retired. Feature with no tasks lands in Backlog (epic() default).
        // Verify via project-detail that the feature shows without a drill-down button.
        $feature = Activity::factory()->epic()->create([
            'title' => 'Feature Sem Tasks',
            'project_id' => $project->id,
            'status' => ActivityStatus::Backlog,
        ]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->assertSee('Feature Sem Tasks')
            ->assertDontSee('Ver 0 tasks');
    });
});

// ========================================
// Drill-down on project-detail page
// ========================================

describe('Drill-down on project-detail page', function () {
    test('enterDrill sets drillFeatureId on project-detail', function () {
        $project = Project::factory()->create();
        $feature = Activity::factory()->epic()->create(['project_id' => $project->id]);
        Activity::factory()->issue()->todo()->create(['parent_id' => $feature->id, 'project_id' => $project->id]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->call('enterDrill', $feature->id)
            ->assertSet('drillFeatureId', $feature->id);
    });

    test('exitDrill clears drillFeatureId on project-detail', function () {
        $project = Project::factory()->create();
        $feature = Activity::factory()->epic()->create(['project_id' => $project->id]);
        Activity::factory()->issue()->todo()->create(['parent_id' => $feature->id, 'project_id' => $project->id]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->call('enterDrill', $feature->id)
            ->call('exitDrill')
            ->assertSet('drillFeatureId', null);
    });

    test('drill mode renders tasks on project-detail', function () {
        $project = Project::factory()->create();
        $feature = Activity::factory()->epic()->create([
            'project_id' => $project->id,
            'title' => 'PD Drill Feature',
        ]);
        Activity::factory()->issue()->todo()->create([
            'parent_id' => $feature->id,
            'project_id' => $project->id,
            'title' => 'PD Task Todo',
        ]);
        Activity::factory()->issue()->doing()->create([
            'parent_id' => $feature->id,
            'project_id' => $project->id,
            'title' => 'PD Task Doing',
        ]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->call('enterDrill', $feature->id)
            ->assertSee('PD Task Todo')
            ->assertSee('PD Task Doing')
            ->assertSee('Fatia')
            ->assertSee('Voltar para features');
    });

    test('direct URL access with feature param on project-detail', function () {
        $project = Project::factory()->create();
        $feature = Activity::factory()->epic()->create([
            'project_id' => $project->id,
            'title' => 'PD URL Feature',
        ]);
        Activity::factory()->issue()->todo()->create([
            'parent_id' => $feature->id,
            'project_id' => $project->id,
            'title' => 'PD URL Task',
        ]);

        $this->get(route('project.detail', ['slug' => $project->slug, 'feature' => $feature->id]))
            ->assertSee('PD URL Feature')
            ->assertSee('PD URL Task')
            ->assertSee('Voltar para features');
    });

    test('handleTaskSort works on project-detail', function () {
        $project = Project::factory()->create();
        $feature = Activity::factory()->epic()->create(['project_id' => $project->id]);
        $task = Activity::factory()->issue()->backlog()->create([
            'parent_id' => $feature->id,
            'project_id' => $project->id,
        ]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->call('enterDrill', $feature->id)
            ->call('handleTaskSort', $task->id, 0, 'doing');

        $task->refresh();
        expect($task->status)->toBe(ActivityStatus::Doing);
    });
});
