<?php

use App\Enums\FeaturePriority;
use App\Enums\FeatureStatus;
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

describe('Features Kanban Page', function () {
    test('features page requires authentication', function () {
        auth()->logout();

        $this->get(route('work'))
            ->assertRedirect(route('login'));
    });

    test('features page loads successfully', function () {
        $this->get(route('work'))
            ->assertOk()
            ->assertSee('Work');
    });

    test('displays features in correct columns by explicit status', function () {
        // Features now use explicit status (not derived from tasks)
        $backlogFeature = Feature::factory()->backlog()->create(['title' => 'Backlog Feature']);
        $todoFeature = Feature::factory()->todo()->create(['title' => 'Todo Feature']);
        $doingFeature = Feature::factory()->doing()->create(['title' => 'Doing Feature']);
        $doneFeature = Feature::factory()->done()->create(['title' => 'Done Feature']);

        $this->get(route('work'))
            ->assertSee('Backlog Feature')
            ->assertSee('Todo Feature')
            ->assertSee('Doing Feature')
            ->assertSee('Done Feature');
    });

    test('displays feature progress correctly', function () {
        $feature = Feature::factory()->todo()->create(['title' => 'Progress Feature']);

        // 2 done, 2 not done = 50%
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Done]);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Done]);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Doing]);

        $this->get(route('work'))
            ->assertSee('Progress Feature')
            ->assertSee('2/4 tasks');
    });

    test('filters features by project', function () {
        $project1 = Project::factory()->create(['name' => 'Project Alpha']);
        $project2 = Project::factory()->create(['name' => 'Project Beta']);

        Feature::factory()->todo()->create([
            'title' => 'Feature Alpha',
            'project_id' => $project1->id,
        ]);

        Feature::factory()->todo()->create([
            'title' => 'Feature Beta',
            'project_id' => $project2->id,
        ]);

        $this->get(route('work', ['filterProject' => $project1->id]))
            ->assertSee('Feature Alpha')
            ->assertDontSee('Feature Beta');
    });

    test('filters features by priority', function () {
        Feature::factory()->todo()->create([
            'title' => 'High Priority Feature',
            'priority' => FeaturePriority::High,
        ]);

        Feature::factory()->todo()->create([
            'title' => 'Low Priority Feature',
            'priority' => FeaturePriority::Low,
        ]);

        $this->get(route('work', ['filterPriority' => 'high']))
            ->assertSee('High Priority Feature')
            ->assertDontSee('Low Priority Feature');
    });

    test('displays project badge on feature card', function () {
        $project = Project::factory()->create([
            'name' => 'My Project',
            'emoji' => '🚀',
        ]);

        Feature::factory()->todo()->create([
            'title' => 'Feature with Project',
            'project_id' => $project->id,
        ]);

        $this->get(route('work'))
            ->assertSee('Feature with Project')
            ->assertSee('My Project');
    });

    test('shows empty state when no features', function () {
        $this->get(route('work'))
            ->assertSee('Nenhuma feature');
    });
});

describe('Feature Modal', function () {
    test('can open create feature modal', function () {
        Livewire::test('feature-modal')
            ->call('open')
            ->assertSet('featureId', null)
            ->assertSet('title', '');
    });

    test('can create a new feature', function () {
        $project = Project::factory()->create();

        Livewire::test('feature-modal')
            ->call('open')
            ->set('title', 'New Feature')
            ->set('projectId', $project->id)
            ->set('priority', 'high')
            ->set('spec', '## Description\n\nThis is the spec.')
            ->call('save')
            ->assertDispatched('feature-created');

        $this->assertDatabaseHas('features', [
            'title' => 'New Feature',
            'project_id' => $project->id,
            'priority' => 'high',
        ]);
    });

    test('can open edit feature modal', function () {
        $feature = Feature::factory()->create([
            'title' => 'Existing Feature',
            'priority' => FeaturePriority::Medium,
        ]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSet('featureId', $feature->id)
            ->assertSet('title', 'Existing Feature')
            ->assertSet('priority', 'medium');
    });

    test('can update a feature', function () {
        $feature = Feature::factory()->create([
            'title' => 'Old Title',
        ]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->set('title', 'New Title')
            ->set('priority', 'urgent')
            ->call('save')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseHas('features', [
            'id' => $feature->id,
            'title' => 'New Title',
            'priority' => 'urgent',
        ]);
    });

    test('can delete a feature', function () {
        $feature = Feature::factory()->create();

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->call('confirmDelete')
            ->assertSet('showDeleteConfirm', true)
            ->call('delete')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseMissing('features', ['id' => $feature->id]);
    });

    test('delete unlinks tasks but does not delete them', function () {
        $feature = Feature::factory()->create();
        $task = Task::factory()->create(['feature_id' => $feature->id]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->call('confirmDelete')
            ->call('delete');

        $this->assertDatabaseMissing('features', ['id' => $feature->id]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'feature_id' => null,
        ]);
    });

    test('displays feature status and progress when editing', function () {
        $feature = Feature::factory()->todo()->create(['title' => 'Feature with Tasks']);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Done]);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSee('Status')
            ->assertSee('Progresso')
            ->assertSee('1/2 tasks');
    });

    test('can start timer on feature', function () {
        $feature = Feature::factory()->create();

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->call('startTimer')
            ->assertDispatched('timer-started')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseHas('time_entries', [
            'feature_id' => $feature->id,
            'stopped_at' => null,
        ]);
    });

    test('validates title is required', function () {
        Livewire::test('feature-modal')
            ->call('open')
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required']);
    });
});

describe('Features Kanban Expandable Tasks', function () {
    test('can toggle task list expansion', function () {
        $feature = Feature::factory()->todo()->create();
        Task::factory()->count(3)->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        Livewire::test('pages::features')
            ->assertSet('expanded', [])
            ->call('toggleExpanded', $feature->id)
            ->assertSet("expanded.{$feature->id}", true)
            ->call('toggleExpanded', $feature->id)
            ->assertSet("expanded.{$feature->id}", false);
    });

    test('displays task count on feature card', function () {
        $feature = Feature::factory()->todo()->create(['title' => 'Multi Task Feature']);
        Task::factory()->count(5)->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        $this->get(route('work'))
            ->assertSee('5 tasks');
    });
});

describe('Feature Kanban Sort', function () {
    test('handleFeatureSort persists new status and sort_order', function () {
        $feature = Feature::factory()->create([
            'status' => FeatureStatus::Backlog,
            'sort_order' => 0,
        ]);

        Livewire::test('pages::features')
            ->call('handleFeatureSort', $feature->id, 1, 'todo')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseHas('features', [
            'id' => $feature->id,
            'status' => 'todo',
            'sort_order' => 1,
        ]);
    });

    test('handleFeatureSort moves feature to done column', function () {
        $feature = Feature::factory()->create([
            'status' => FeatureStatus::Doing,
            'sort_order' => 0,
        ]);

        Livewire::test('pages::features')
            ->call('handleFeatureSort', $feature->id, 0, 'done')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseHas('features', [
            'id' => $feature->id,
            'status' => 'done',
        ]);
    });
});
