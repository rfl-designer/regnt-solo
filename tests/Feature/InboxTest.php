<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        ->assertSee('Caixa de Entrada');
});

test('inbox lists tasks with inbox status', function () {
    $task = Activity::factory()->create(['title' => 'Minha task inbox']);

    Livewire::test('pages::inbox')
        ->assertSee('Minha task inbox');
});

test('inbox does not list tasks with other statuses', function () {
    Activity::factory()->backlog()->create(['title' => 'Task backlog']);
    Activity::factory()->todo()->create(['title' => 'Task todo']);
    Activity::factory()->doing()->create(['title' => 'Task doing']);
    Activity::factory()->done()->create(['title' => 'Task done']);

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
    Activity::factory()->create(['project_id' => $project->id]);

    Livewire::test('pages::inbox')
        ->assertSee('🚀')
        ->assertSee('Meu Projeto');
});

test('move to status updates task status', function () {
    $task = Activity::factory()->create(['title' => 'Task para backlog']);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'backlog')
        ->assertDispatched('task-moved');

    expect($task->fresh()->status)->toBe(ActivityStatus::Backlog);
});

test('move to status only works for inbox tasks', function () {
    $task = Activity::factory()->backlog()->create();

    $this->expectException(ModelNotFoundException::class);

    Livewire::test('pages::inbox')
        ->call('moveToStatus', $task->id, 'todo');
});

test('assign project updates task project', function () {
    $task = Activity::factory()->create();
    $project = Project::factory()->create();

    Livewire::test('pages::inbox')
        ->call('assignProject', $task->id, (string) $project->id);

    expect($task->fresh()->project_id)->toBe($project->id);
});

test('assign project with empty string removes project', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->create(['project_id' => $project->id]);

    Livewire::test('pages::inbox')
        ->call('assignProject', $task->id, '');

    expect($task->fresh()->project_id)->toBeNull();
});

test('confirm delete sets task info and opens modal', function () {
    $task = Activity::factory()->create(['title' => 'Task para excluir']);

    Livewire::test('pages::inbox')
        ->call('confirmDelete', $task->id)
        ->assertSet('deletingTaskId', $task->id)
        ->assertSet('deletingTaskTitle', 'Task para excluir')
        ->assertSet('showDeleteModal', true);
});

test('delete task removes it from database', function () {
    $task = Activity::factory()->create(['title' => 'Task para excluir']);

    Livewire::test('pages::inbox')
        ->call('confirmDelete', $task->id)
        ->call('deleteTask')
        ->assertDispatched('task-moved');

    expect(Activity::find($task->id))->toBeNull();
});

test('delete task only works for inbox tasks', function () {
    $task = Activity::factory()->backlog()->create();

    $this->expectException(ModelNotFoundException::class);

    Livewire::test('pages::inbox')
        ->set('deletingTaskId', $task->id)
        ->call('deleteTask');
});

test('inbox listens to task-created event', function () {
    $component = Livewire::test('pages::inbox')
        ->assertSee('Nenhuma task no inbox');

    Activity::factory()->create(['title' => 'Nova task criada']);

    $component->dispatch('task-created')
        ->assertSee('Nova task criada');
});

test('inbox badge shows count of inbox tasks', function () {
    Activity::factory()->count(3)->create();
    Activity::factory()->backlog()->create();

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

    Activity::factory()->create();

    Livewire::test('inbox-badge')
        ->dispatch('task-created')
        ->assertSee('1');
});

test('inbox badge updates on task-moved event', function () {
    $task = Activity::factory()->create();

    $badge = Livewire::test('inbox-badge')
        ->assertSee('1');

    $task->update(['status' => ActivityStatus::Backlog]);

    $badge->dispatch('task-moved')
        ->assertDontSeeHtml('data-flux-badge');
});

test('update service class changes task service class', function () {
    $task = Activity::factory()->create(['service_class' => ServiceClass::Standard]);

    Livewire::test('pages::inbox')
        ->call('updateServiceClass', $task->id, 'intangible');

    expect($task->fresh()->service_class)->toBe(ServiceClass::Intangible);
});

test('update service class only works for inbox tasks', function () {
    $task = Activity::factory()->backlog()->create();

    $this->expectException(ModelNotFoundException::class);

    Livewire::test('pages::inbox')
        ->call('updateServiceClass', $task->id, 'emergency');
});

test('update service class to fixed_date without a due date is refused with a toast', function () {
    $task = Activity::factory()->create(['service_class' => ServiceClass::Standard, 'due_date' => null]);

    Livewire::test('pages::inbox')
        ->call('updateServiceClass', $task->id, 'fixed_date')
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return ($params['slots']['text'] ?? null) === FixedDateRequiresDueDateException::MESSAGE;
        });

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('inbox shows service class dropdown for each task', function () {
    Activity::factory()->create(['service_class' => ServiceClass::Emergency]);

    Livewire::test('pages::inbox')
        ->assertSee('Emergência')
        ->assertSee('Classe de serviço');
});
