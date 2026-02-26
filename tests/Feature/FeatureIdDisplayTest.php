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

describe('Feature ID on Cards', function () {
    test('feature card renders #F-{id} with the correct ID', function () {
        $feature = Feature::factory()->create(['title' => 'Test Feature']);
        Task::factory()->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        $this->get(route('work'))
            ->assertSee("#F-{$feature->id}");
    });

    test('feature ID appears on Work page kanban', function () {
        $feature1 = Feature::factory()->create(['title' => 'Feature Alpha']);
        Task::factory()->create(['feature_id' => $feature1->id, 'status' => TaskStatus::Todo]);

        $feature2 = Feature::factory()->create(['title' => 'Feature Beta']);
        Task::factory()->create(['feature_id' => $feature2->id, 'status' => TaskStatus::Doing]);

        $response = $this->get(route('work'));

        $response->assertSee("#F-{$feature1->id}");
        $response->assertSee("#F-{$feature2->id}");
    });

    test('feature ID appears on project detail kanban', function () {
        $project = Project::factory()->create();
        $feature = Feature::factory()->create([
            'title' => 'Project Feature',
            'project_id' => $project->id,
        ]);
        Task::factory()->create([
            'feature_id' => $feature->id,
            'project_id' => $project->id,
            'status' => TaskStatus::Todo,
        ]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->assertSee("#F-{$feature->id}");
    });
});

describe('Feature ID on Modal', function () {
    test('feature modal displays ID when editing', function () {
        $feature = Feature::factory()->create(['title' => 'Editable Feature']);

        Livewire::test('feature-modal')
            ->call('open', $feature->id)
            ->assertSee("#F-{$feature->id}");
    });

    test('feature modal does not display ID when creating', function () {
        Livewire::test('feature-modal')
            ->call('open')
            ->assertDontSee('#F-');
    });
});
