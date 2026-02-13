<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('inbox route requires authentication', function () {
    auth()->logout();

    $this->get(route('inbox'))
        ->assertRedirect(route('login'));
});

test('inbox page renders correctly for authenticated users', function () {
    $this->get(route('inbox'))
        ->assertOk();
});

test('inbox component renders successfully', function () {
    Livewire::test('pages::inbox')
        ->assertSuccessful()
        ->assertSee('Inbox');
});

test('inbox lists tasks with inbox status', function () {
    $task = Task::factory()->create(['title' => 'Minha task inbox']);

    Livewire::test('pages::inbox')
        ->assertSee('Minha task inbox');
});

test('inbox does not list tasks with other statuses', function () {
    Task::factory()->backlog()->create(['title' => 'Task backlog']);
    Task::factory()->todo()->create(['title' => 'Task todo']);
    Task::factory()->doing()->create(['title' => 'Task doing']);
    Task::factory()->done()->create(['title' => 'Task done']);

    Livewire::test('pages::inbox')
        ->assertDontSee('Task backlog')
        ->assertDontSee('Task todo')
        ->assertDontSee('Task doing')
        ->assertDontSee('Task done');
});

test('inbox shows empty state when no tasks exist', function () {
    Livewire::test('pages::inbox')
        ->assertSee('Nenhuma task no inbox');
});

test('inbox shows project info when task has a project', function () {
    $project = Project::factory()->create(['name' => 'Meu Projeto', 'emoji' => '🚀']);
    Task::factory()->create(['project_id' => $project->id]);

    Livewire::test('pages::inbox')
        ->assertSee('🚀')
        ->assertSee('Meu Projeto');
});

test('move to backlog updates task status', function () {
    $task = Task::factory()->create(['title' => 'Task para backlog']);

    Livewire::test('pages::inbox')
        ->call('moveToBacklog', $task->id)
        ->assertDispatched('task-moved');

    expect($task->fresh()->status)->toBe(TaskStatus::Backlog);
});

test('move to backlog only works for inbox tasks', function () {
    $task = Task::factory()->backlog()->create();

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    Livewire::test('pages::inbox')
        ->call('moveToBacklog', $task->id);
});

test('assign project updates task project', function () {
    $task = Task::factory()->create();
    $project = Project::factory()->create();

    Livewire::test('pages::inbox')
        ->call('assignProject', $task->id, (string) $project->id);

    expect($task->fresh()->project_id)->toBe($project->id);
});

test('assign project with empty string removes project', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    Livewire::test('pages::inbox')
        ->call('assignProject', $task->id, '');

    expect($task->fresh()->project_id)->toBeNull();
});

test('confirm delete sets task info and opens modal', function () {
    $task = Task::factory()->create(['title' => 'Task para excluir']);

    Livewire::test('pages::inbox')
        ->call('confirmDelete', $task->id)
        ->assertSet('deletingTaskId', $task->id)
        ->assertSet('deletingTaskTitle', 'Task para excluir')
        ->assertSet('showDeleteModal', true);
});

test('delete task removes it from database', function () {
    $task = Task::factory()->create(['title' => 'Task para excluir']);

    Livewire::test('pages::inbox')
        ->call('confirmDelete', $task->id)
        ->call('deleteTask')
        ->assertDispatched('task-moved');

    expect(Task::find($task->id))->toBeNull();
});

test('delete task only works for inbox tasks', function () {
    $task = Task::factory()->backlog()->create();

    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    Livewire::test('pages::inbox')
        ->set('deletingTaskId', $task->id)
        ->call('deleteTask');
});

test('inbox listens to task-created event', function () {
    Livewire::test('pages::inbox')
        ->assertSee('Nenhuma task no inbox');

    Task::factory()->create(['title' => 'Nova task criada']);

    Livewire::test('pages::inbox')
        ->dispatch('task-created')
        ->assertSee('Nova task criada');
});

test('inbox badge shows count of inbox tasks', function () {
    Task::factory()->count(3)->create();
    Task::factory()->backlog()->create();

    Livewire::test('inbox-badge')
        ->assertSee('3');
});

test('inbox badge hides when count is zero', function () {
    Livewire::test('inbox-badge')
        ->assertDontSeeHtml('data-flux-badge');
});

test('inbox badge updates on task-created event', function () {
    Livewire::test('inbox-badge')
        ->assertDontSeeHtml('data-flux-badge');

    Task::factory()->create();

    Livewire::test('inbox-badge')
        ->dispatch('task-created')
        ->assertSee('1');
});

test('inbox badge updates on task-moved event', function () {
    $task = Task::factory()->create();

    $badge = Livewire::test('inbox-badge')
        ->assertSee('1');

    $task->update(['status' => TaskStatus::Backlog]);

    $badge->dispatch('task-moved')
        ->assertDontSeeHtml('data-flux-badge');
});
