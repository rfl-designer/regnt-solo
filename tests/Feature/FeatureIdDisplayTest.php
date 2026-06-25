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

describe('Feature ID on Cards', function () {
    test('feature ID appears on project detail kanban', function () {
        $project = Project::factory()->create();
        $feature = Activity::factory()->epic()->create([
            'title' => 'Project Feature',
            'project_id' => $project->id,
            'status' => ActivityStatus::Todo,
        ]);
        Activity::factory()->create([
            'parent_id' => $feature->id,
            'project_id' => $project->id,
            'status' => ActivityStatus::Todo,
        ]);

        Livewire::test('pages::project-detail', ['slug' => $project->slug])
            ->assertSee("#F-{$feature->id}");
    });
});

describe('Feature ID on Modal', function () {
    test('feature modal displays ID when editing', function () {
        $feature = Activity::factory()->epic()->create(['title' => 'Editable Feature']);

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
