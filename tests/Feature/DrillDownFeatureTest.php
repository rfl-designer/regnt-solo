<?php

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

// ========================================
// "Ver Tasks" button on feature card
// ========================================

describe('Ver Tasks button', function () {
    test('appears on feature card when feature has tasks', function () {
        $feature = Feature::factory()->create(['title' => 'Feature Com Tasks']);
        Task::factory()->count(3)->todo()->create(['feature_id' => $feature->id]);

        $this->get(route('work'))
            ->assertSee('Ver 3 tasks');
    });

    test('does not appear on feature card when feature has zero tasks', function () {
        $project = Project::factory()->create();

        // Feature with no tasks (Draft) won't have drill-down button in feature-card template
        // Verify via project-detail where Draft column exists
        $feature = Feature::factory()->create([
            'title' => 'Feature Sem Tasks',
            'project_id' => $project->id,
        ]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->assertSee('Feature Sem Tasks')
            ->assertDontSee('Ver 0 tasks');
    });
});

// ========================================
// enterDrill / exitDrill on Work page
// ========================================

describe('Drill-down on Work page (features)', function () {
    test('enterDrill sets drillFeatureId', function () {
        $feature = Feature::factory()->create();
        Task::factory()->todo()->create(['feature_id' => $feature->id]);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->assertSet('drillFeatureId', $feature->id);
    });

    test('exitDrill clears drillFeatureId', function () {
        $feature = Feature::factory()->create();
        Task::factory()->todo()->create(['feature_id' => $feature->id]);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->assertSet('drillFeatureId', $feature->id)
            ->call('exitDrill')
            ->assertSet('drillFeatureId', null);
    });

    test('drill mode renders task kanban with 4 columns', function () {
        $feature = Feature::factory()->create(['title' => 'Drill Feature']);
        Task::factory()->backlog()->create(['feature_id' => $feature->id, 'title' => 'Task Backlog']);
        Task::factory()->todo()->create(['feature_id' => $feature->id, 'title' => 'Task Todo']);
        Task::factory()->doing()->create(['feature_id' => $feature->id, 'title' => 'Task Doing']);
        Task::factory()->done()->create(['feature_id' => $feature->id, 'title' => 'Task Done']);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->assertSee('Task Backlog')
            ->assertSee('Task Todo')
            ->assertSee('Task Doing')
            ->assertSee('Task Done');
    });

    test('drill mode only shows tasks from selected feature', function () {
        $feature = Feature::factory()->create(['title' => 'Target Feature']);
        $otherFeature = Feature::factory()->create(['title' => 'Other Feature']);

        Task::factory()->todo()->create(['feature_id' => $feature->id, 'title' => 'Target Task']);
        Task::factory()->todo()->create(['feature_id' => $otherFeature->id, 'title' => 'Other Task']);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->assertSee('Target Task')
            ->assertDontSee('Other Task');
    });

    test('drill mode header shows feature title and back button', function () {
        $feature = Feature::factory()->create(['title' => 'Header Feature']);
        Task::factory()->doing()->create(['feature_id' => $feature->id]);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->assertSee('Header Feature')
            ->assertSee('Voltar para features');
    });

    test('drill mode header shows progress', function () {
        $feature = Feature::factory()->create(['title' => 'Progress Feature']);
        Task::factory()->done()->create(['feature_id' => $feature->id]);
        Task::factory()->done()->create(['feature_id' => $feature->id]);
        Task::factory()->todo()->create(['feature_id' => $feature->id]);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->assertSee('2/3 concluídas');
    });

    test('direct URL access with feature query param enters drill mode', function () {
        $feature = Feature::factory()->create(['title' => 'URL Feature']);
        Task::factory()->todo()->create(['feature_id' => $feature->id, 'title' => 'URL Task']);

        $this->get(route('work', ['feature' => $feature->id]))
            ->assertSee('URL Feature')
            ->assertSee('URL Task')
            ->assertSee('Voltar para features');
    });
});

// ========================================
// handleTaskSort (drag-drop) in drill mode
// ========================================

describe('Drag-drop in drill mode', function () {
    test('handleTaskSort updates task status', function () {
        $feature = Feature::factory()->create();
        $task = Task::factory()->todo()->create([
            'feature_id' => $feature->id,
        ]);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->call('handleTaskSort', $task->id, 0, 'doing');

        $task->refresh();
        expect($task->status)->toBe(TaskStatus::Doing);
    });

    test('handleTaskSort to Done marks task as done with completed_at', function () {
        $feature = Feature::factory()->create();
        $task = Task::factory()->todo()->create([
            'feature_id' => $feature->id,
        ]);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->call('handleTaskSort', $task->id, 0, 'done');

        $task->refresh();
        expect($task->status)->toBe(TaskStatus::Done);
        expect($task->completed_at)->not->toBeNull();
    });

    test('handleTaskSort from Done clears completed_at', function () {
        $feature = Feature::factory()->create();
        $task = Task::factory()->done()->create([
            'feature_id' => $feature->id,
        ]);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->call('handleTaskSort', $task->id, 0, 'todo');

        $task->refresh();
        expect($task->status)->toBe(TaskStatus::Todo);
        expect($task->completed_at)->toBeNull();
    });

    test('handleTaskSort rejects tasks not belonging to drilled feature', function () {
        $feature = Feature::factory()->create();
        $otherFeature = Feature::factory()->create();
        $task = Task::factory()->todo()->create([
            'feature_id' => $otherFeature->id,
        ]);

        Livewire::test('pages::features')
            ->call('enterDrill', $feature->id)
            ->call('handleTaskSort', $task->id, 0, 'doing');

        $task->refresh();
        expect($task->status)->toBe(TaskStatus::Todo);
    });
});

// ========================================
// Drill-down on project-detail page
// ========================================

describe('Drill-down on project-detail page', function () {
    test('enterDrill sets drillFeatureId on project-detail', function () {
        $project = Project::factory()->create();
        $feature = Feature::factory()->create(['project_id' => $project->id]);
        Task::factory()->todo()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->call('enterDrill', $feature->id)
            ->assertSet('drillFeatureId', $feature->id);
    });

    test('exitDrill clears drillFeatureId on project-detail', function () {
        $project = Project::factory()->create();
        $feature = Feature::factory()->create(['project_id' => $project->id]);
        Task::factory()->todo()->create(['feature_id' => $feature->id, 'project_id' => $project->id]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->call('enterDrill', $feature->id)
            ->call('exitDrill')
            ->assertSet('drillFeatureId', null);
    });

    test('drill mode renders tasks on project-detail', function () {
        $project = Project::factory()->create();
        $feature = Feature::factory()->create([
            'project_id' => $project->id,
            'title' => 'PD Drill Feature',
        ]);
        Task::factory()->todo()->create([
            'feature_id' => $feature->id,
            'project_id' => $project->id,
            'title' => 'PD Task Todo',
        ]);
        Task::factory()->doing()->create([
            'feature_id' => $feature->id,
            'project_id' => $project->id,
            'title' => 'PD Task Doing',
        ]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->call('enterDrill', $feature->id)
            ->assertSee('PD Task Todo')
            ->assertSee('PD Task Doing')
            ->assertSee('Voltar para features');
    });

    test('direct URL access with feature param on project-detail', function () {
        $project = Project::factory()->create();
        $feature = Feature::factory()->create([
            'project_id' => $project->id,
            'title' => 'PD URL Feature',
        ]);
        Task::factory()->todo()->create([
            'feature_id' => $feature->id,
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
        $feature = Feature::factory()->create(['project_id' => $project->id]);
        $task = Task::factory()->backlog()->create([
            'feature_id' => $feature->id,
            'project_id' => $project->id,
        ]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->call('enterDrill', $feature->id)
            ->call('handleTaskSort', $task->id, 0, 'doing');

        $task->refresh();
        expect($task->status)->toBe(TaskStatus::Doing);
    });
});
