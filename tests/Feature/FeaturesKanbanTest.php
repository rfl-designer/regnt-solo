<?php

use App\Enums\ActivityPriority;
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
        $backlogFeature = Activity::factory()->epic()->backlog()->create(['title' => 'Backlog Feature']);
        $todoFeature = Activity::factory()->epic()->todo()->create(['title' => 'Todo Feature']);
        $doingFeature = Activity::factory()->epic()->doing()->create(['title' => 'Doing Feature']);
        $doneFeature = Activity::factory()->epic()->done()->create(['title' => 'Done Feature']);

        $this->get(route('work'))
            ->assertSee('Backlog Feature')
            ->assertSee('Todo Feature')
            ->assertSee('Doing Feature')
            ->assertSee('Done Feature');
    });

    test('displays feature progress correctly', function () {
        $feature = Activity::factory()->epic()->todo()->create(['title' => 'Progress Feature']);

        // 2 done, 2 not done = 50%
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Done]);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Done]);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Doing]);

        $this->get(route('work'))
            ->assertSee('Progress Feature')
            ->assertSee('2/4 tasks');
    });

    test('filters features by project', function () {
        $project1 = Project::factory()->create(['name' => 'Project Alpha']);
        $project2 = Project::factory()->create(['name' => 'Project Beta']);

        Activity::factory()->epic()->todo()->create([
            'title' => 'Feature Alpha',
            'project_id' => $project1->id,
        ]);

        Activity::factory()->epic()->todo()->create([
            'title' => 'Feature Beta',
            'project_id' => $project2->id,
        ]);

        $this->get(route('work', ['filterProject' => $project1->id]))
            ->assertSee('Feature Alpha')
            ->assertDontSee('Feature Beta');
    });

    test('filters features by priority', function () {
        Activity::factory()->epic()->todo()->create([
            'title' => 'High Priority Feature',
            'priority' => ActivityPriority::High,
        ]);

        Activity::factory()->epic()->todo()->create([
            'title' => 'Low Priority Feature',
            'priority' => ActivityPriority::Low,
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

        Activity::factory()->epic()->todo()->create([
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

        $this->assertDatabaseHas('activities', [
            'title' => 'New Feature',
            'project_id' => $project->id,
            'priority' => 'high',
        ]);
    });

    test('can open edit feature modal', function () {
        $feature = Activity::factory()->epic()->create([
            'title' => 'Existing Feature',
            'priority' => ActivityPriority::Medium,
        ]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSet('featureId', $feature->id)
            ->assertSet('title', 'Existing Feature')
            ->assertSet('priority', 'medium');
    });

    test('can update a feature', function () {
        $feature = Activity::factory()->epic()->create([
            'title' => 'Old Title',
        ]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->set('title', 'New Title')
            ->set('priority', 'urgent')
            ->call('save')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseHas('activities', [
            'id' => $feature->id,
            'title' => 'New Title',
            'priority' => 'urgent',
        ]);
    });

    test('can delete a feature', function () {
        $feature = Activity::factory()->epic()->create();

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->call('confirmDelete')
            ->assertSet('showDeleteConfirm', true)
            ->call('delete')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseMissing('activities', ['id' => $feature->id]);
    });

    test('delete unlinks tasks but does not delete them', function () {
        $feature = Activity::factory()->epic()->create();
        $task = Activity::factory()->create(['parent_id' => $feature->id]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->call('confirmDelete')
            ->call('delete');

        $this->assertDatabaseMissing('activities', ['id' => $feature->id]);
        $this->assertDatabaseHas('activities', [
            'id' => $task->id,
            'parent_id' => null,
        ]);
    });

    test('displays feature status and progress when editing', function () {
        $feature = Activity::factory()->epic()->todo()->create(['title' => 'Feature with Tasks']);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Done]);
        Activity::factory()->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSee('Status')
            ->assertSee('Progresso')
            ->assertSee('1/2 tasks');
    });

    test('can start timer on feature', function () {
        $feature = Activity::factory()->epic()->create();

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->call('startTimer')
            ->assertDispatched('timer-started')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseHas('time_entries', [
            'activity_id' => $feature->id,
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
        $feature = Activity::factory()->epic()->todo()->create();
        Activity::factory()->count(3)->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        Livewire::test('pages::features')
            ->assertSet('expanded', [])
            ->call('toggleExpanded', $feature->id)
            ->assertSet("expanded.{$feature->id}", true)
            ->call('toggleExpanded', $feature->id)
            ->assertSet("expanded.{$feature->id}", false);
    });

    test('displays task count on feature card', function () {
        $feature = Activity::factory()->epic()->todo()->create(['title' => 'Multi Task Feature']);
        Activity::factory()->count(5)->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        $this->get(route('work'))
            ->assertSee('5 tasks');
    });
});

describe('Feature Kanban Sort', function () {
    test('handleFeatureSort persists new status and sort_order', function () {
        $feature = Activity::factory()->epic()->create([
            'status' => ActivityStatus::Backlog,
            'sort_order' => 0,
        ]);

        Livewire::test('pages::features')
            ->call('handleFeatureSort', $feature->id, 1, 'todo')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseHas('activities', [
            'id' => $feature->id,
            'status' => 'todo',
            'sort_order' => 0,
        ]);
    });

    test('handleFeatureSort moves feature to done column', function () {
        $feature = Activity::factory()->epic()->create([
            'status' => ActivityStatus::Doing,
            'sort_order' => 0,
        ]);

        Livewire::test('pages::features')
            ->call('handleFeatureSort', $feature->id, 0, 'done')
            ->assertDispatched('feature-updated');

        $this->assertDatabaseHas('activities', [
            'id' => $feature->id,
            'status' => 'done',
        ]);
    });
});
