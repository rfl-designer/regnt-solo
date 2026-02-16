<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('mini kanban shows initial limit of 10 tasks per column', function () {
    $project = Project::factory()->create();

    // Create 15 tasks in backlog
    $tasks = Task::factory()->count(15)->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Backlog,
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should show only 10 tasks
    foreach ($tasks->take(10) as $task) {
        $component->assertSee($task->title);
    }

    // Should not show tasks beyond the limit
    foreach ($tasks->skip(10) as $task) {
        $component->assertDontSee($task->title);
    }
});

test('load more button increases limit by 10', function () {
    $project = Project::factory()->create();

    // Create 25 tasks in todo
    $tasks = Task::factory()->count(25)->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Todo,
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should not show tasks beyond initial limit
    $component->assertDontSee($tasks[20]->title);

    // Load more
    $component->call('loadMore', 'todo');

    // Should now show more tasks
    $component->assertSee($tasks[15]->title);

    // Should still not show all
    $component->assertDontSee($tasks[24]->title);

    // Load more again
    $component->call('loadMore', 'todo');

    // Should now show all tasks
    $component->assertSee($tasks[24]->title);
});

test('each column has independent lazy loading', function () {
    $project = Project::factory()->create();

    // Create different counts per status
    $backlogTasks = Task::factory()->count(15)->create(['project_id' => $project->id, 'status' => TaskStatus::Backlog]);
    $todoTasks = Task::factory()->count(25)->create(['project_id' => $project->id, 'status' => TaskStatus::Todo]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Load more in Todo only
    $component->call('loadMore', 'todo');

    // Todo should now show 20
    $component->assertSee($todoTasks[15]->title);

    // Backlog should still show only 10
    $component->assertDontSee($backlogTasks[14]->title);
});

test('mini kanban renders load more button when there are more tasks', function () {
    $project = Project::factory()->create();

    Task::factory()->count(15)->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Backlog,
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should render the load more button
    $component->assertSeeHtml('Carregar mais (5 restantes)');
});

test('mini kanban does not render load more button when all tasks are shown', function () {
    $project = Project::factory()->create();

    Task::factory()->count(8)->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Backlog,
    ]);

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Should not render the load more button
    $component->assertDontSeeHtml('Carregar mais');
});

test('limits property has correct initial values', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    expect($component->get('limits'))->toBe([
        'backlog' => 10,
        'todo' => 10,
        'doing' => 10,
        'done' => 10,
    ]);
});

test('load more updates the correct status limit', function () {
    $project = Project::factory()->create();

    $component = Livewire::test('pages::project-detail', ['slug' => $project->slug]);

    // Load more in backlog
    $component->call('loadMore', 'backlog');

    expect($component->get('limits')['backlog'])->toBe(20);
    expect($component->get('limits')['todo'])->toBe(10);
});
