<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('page renders successfully', function () {
    Livewire::test('pages::tasks')
        ->assertSuccessful()
        ->assertSee('Tarefas');
});

test('lists only personal tasks, excluding epics, issues and drafts', function () {
    Activity::factory()->task()->create(['title' => 'Personal Task', 'status' => ActivityStatus::Todo]);
    Activity::factory()->epic()->create(['title' => 'Roadmap Epic']);
    Activity::factory()->issue()->create(['title' => 'Roadmap Issue']);
    Activity::factory()->draft()->create(['title' => 'Loose Draft']);

    Livewire::test('pages::tasks')
        ->assertSee('Personal Task')
        ->assertDontSee('Roadmap Epic')
        ->assertDontSee('Roadmap Issue')
        ->assertDontSee('Loose Draft');
});

test('a task can exist without a project', function () {
    Activity::factory()->task()->create(['title' => 'Standalone Task', 'project_id' => null]);

    Livewire::test('pages::tasks')
        ->assertSee('Standalone Task');
});

test('toggle complete marks a task as done', function () {
    $task = Activity::factory()->task()->create(['status' => ActivityStatus::Todo]);

    Livewire::test('pages::tasks')
        ->call('toggleComplete', $task->id);

    $task->refresh();
    expect($task->status)->toBe(ActivityStatus::Done);
    expect($task->completed_at)->not->toBeNull();
});

test('toggle complete reopens a done task', function () {
    $task = Activity::factory()->task()->done()->create();

    Livewire::test('pages::tasks')
        ->call('toggleComplete', $task->id);

    $task->refresh();
    expect($task->status)->toBe(ActivityStatus::Todo);
    expect($task->completed_at)->toBeNull();
});

test('toggle complete refuses a non-task activity', function () {
    $issue = Activity::factory()->issue()->create(['status' => ActivityStatus::Todo]);

    expect(fn () => Livewire::test('pages::tasks')->call('toggleComplete', $issue->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $issue->refresh();
    expect($issue->status)->toBe(ActivityStatus::Todo);
});

test('filters tasks by project', function () {
    $project = Project::factory()->create();
    Activity::factory()->task()->create(['title' => 'Project Task', 'project_id' => $project->id]);
    Activity::factory()->task()->create(['title' => 'Other Task', 'project_id' => null]);

    Livewire::test('pages::tasks')
        ->set('projectFilter', $project->id)
        ->assertSee('Project Task')
        ->assertDontSee('Other Task');
});

test('searches tasks by title', function () {
    Activity::factory()->task()->create(['title' => 'Cobrar manual de marca']);
    Activity::factory()->task()->create(['title' => 'Email pro designer']);

    Livewire::test('pages::tasks')
        ->set('search', 'manual')
        ->assertSee('Cobrar manual de marca')
        ->assertDontSee('Email pro designer');
});

test('deletes a task with confirmation', function () {
    $task = Activity::factory()->task()->create(['title' => 'Disposable Task']);

    Livewire::test('pages::tasks')
        ->call('confirmDelete', $task->id)
        ->assertSet('deletingTaskId', $task->id)
        ->call('deleteTask');

    $this->assertDatabaseMissing('activities', ['id' => $task->id]);
});

test('delete refuses a non-task activity', function () {
    $epic = Activity::factory()->epic()->create();

    expect(fn () => Livewire::test('pages::tasks')->call('confirmDelete', $epic->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->assertDatabaseHas('activities', ['id' => $epic->id]);
});
