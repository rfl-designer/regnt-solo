<?php

use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('side panel lists the personal tasks of the project', function () {
    $project = Project::factory()->create();
    Activity::factory()->task()->create(['project_id' => $project->id, 'title' => 'Cobrar manual de marca']);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Cobrar manual de marca');
});

test('side panel ignores tasks from another project', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    Activity::factory()->task()->create(['project_id' => $project->id, 'title' => 'Task Desta']);
    Activity::factory()->task()->create(['project_id' => $other->id, 'title' => 'Task Outra']);

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Task Desta')
        ->assertDontSee('Task Outra');
});

test('projectTasks computed contains only type=Task activities', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->task()->create(['project_id' => $project->id]);
    $epic = Activity::factory()->epic()->create(['project_id' => $project->id]);
    $issue = Activity::factory()->issue()->create(['project_id' => $project->id]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    $ids = $component->instance()->projectTasks->pluck('id')->all();

    expect($ids)->toContain($task->id)
        ->not->toContain($epic->id)
        ->not->toContain($issue->id);
});

test('side panel shows empty state when the project has no tasks', function () {
    $project = Project::factory()->create();

    Livewire::test('pages::project-detail', ['slug' => $project->slug])
        ->assertSee('Nenhuma task neste projeto.');
});
