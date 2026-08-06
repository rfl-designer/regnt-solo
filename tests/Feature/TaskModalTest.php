<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('component renders successfully', function () {
    Livewire::test('task-modal')
        ->assertSuccessful();
});

test('opens modal when open-task-modal event is dispatched', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSet('showModal', true)
        ->assertSet('taskId', $task->id)
        ->assertSet('title', $task->title);
});

test('loads task data correctly when opened', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->create([
        'project_id' => $project->id,
        'title' => 'Test Task',
        'description' => 'Test description',
        'status' => ActivityStatus::Todo,
        'priority' => ActivityPriority::High,
        'due_date' => '2026-03-15',
        'estimated_minutes' => 90,
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSet('title', 'Test Task')
        ->assertSet('projectId', (string) $project->id)
        ->assertSet('priority', 'high')
        ->assertSet('status', 'todo')
        ->assertSet('dueDate', '2026-03-15')
        ->assertSet('estimatedMinutes', 90);
});

test('loads time entries when opened', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => '2026-02-13 10:00:00',
        'stopped_at' => '2026-02-13 11:30:00',
        'notes' => 'Working on feature',
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSet('timeEntries.0.id', $entry->id)
        ->assertSet('timeEntries.0.notes', 'Working on feature');
});

test('can save task with updated title', function () {
    $task = Activity::factory()->create(['title' => 'Original Title']);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('title', 'Updated Title')
        ->call('saveTask')
        ->assertDispatched('task-updated');

    expect($task->fresh()->title)->toBe('Updated Title');
});

test('modal stays open after saving', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->call('saveTask')
        ->assertSet('showModal', true);
});

test('validates title is required', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('title', '')
        ->call('saveTask')
        ->assertHasErrors(['title' => 'required']);
});

test('validates title max length', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('title', str_repeat('a', 256))
        ->call('saveTask')
        ->assertHasErrors(['title' => 'max']);
});

test('can update task priority', function () {
    $task = Activity::factory()->create(['priority' => ActivityPriority::Medium]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('priority', 'urgent')
        ->call('saveTask');

    expect($task->fresh()->priority)->toBe(ActivityPriority::Urgent);
});

test('can update task status', function () {
    $task = Activity::factory()->create(['status' => ActivityStatus::Inbox]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('status', 'todo')
        ->call('saveTask');

    expect($task->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('can assign project to task', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->create(['project_id' => null]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('projectId', (string) $project->id)
        ->call('saveTask');

    expect($task->fresh()->project_id)->toBe($project->id);
});

test('can remove project from task', function () {
    $project = Project::factory()->create();
    $task = Activity::factory()->create(['project_id' => $project->id]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('projectId', '')
        ->call('saveTask');

    expect($task->fresh()->project_id)->toBeNull();
});

test('can link a client directly to a task without a project', function () {
    $client = Client::factory()->create();
    $task = Activity::factory()->create(['project_id' => null, 'client_id' => null]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('clientId', (string) $client->id)
        ->call('saveTask');

    expect($task->fresh())
        ->client_id->toBe($client->id)
        ->project_id->toBeNull();
});

test('updatedProjectId clears the direct client selection client-side when a project is chosen', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create();
    $task = Activity::factory()->create(['project_id' => null, 'client_id' => $client->id]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSet('clientId', (string) $client->id)
        ->set('projectId', (string) $project->id)
        ->assertSet('clientId', null);
});

test('saving with a project clears any direct client link', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create();
    $task = Activity::factory()->create(['project_id' => null, 'client_id' => $client->id]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('projectId', (string) $project->id)
        ->call('saveTask');

    expect($task->fresh())
        ->project_id->toBe($project->id)
        ->client_id->toBeNull();
});

test('validates client must exist', function () {
    $task = Activity::factory()->create(['project_id' => null]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('clientId', '999999')
        ->call('saveTask')
        ->assertHasErrors(['clientId' => 'exists']);
});

test('can update due date', function () {
    $task = Activity::factory()->create(['due_date' => null]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('dueDate', '2026-06-15')
        ->call('saveTask');

    expect($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-06-15');
});

test('can update estimated minutes', function () {
    $task = Activity::factory()->create(['estimated_minutes' => null]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('estimatedMinutes', 120)
        ->call('saveTask');

    expect($task->fresh()->estimated_minutes)->toBe(120);
});

test('uses markAsDone when changing status to done', function () {
    $task = Activity::factory()->doing()->create();
    $entry = TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('status', 'done')
        ->call('saveTask');

    $task->refresh();
    expect($task->status)->toBe(ActivityStatus::Done);
    expect($task->completed_at)->not->toBeNull();
    expect($entry->fresh()->stopped_at)->not->toBeNull();
});

test('can delete a time entry', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->create(['activity_id' => $task->id]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->call('deleteTimeEntry', $entry->id);

    expect(TimeEntry::find($entry->id))->toBeNull();
});

test('time entry is removed from local array after deletion', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->create(['activity_id' => $task->id]);

    $component = Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id);

    expect($component->get('timeEntries'))->toHaveCount(1);

    $component->call('deleteTimeEntry', $entry->id);

    expect($component->get('timeEntries'))->toHaveCount(0);
});

test('cannot delete time entry from another task', function () {
    $task = Activity::factory()->create();
    $otherTask = Activity::factory()->create();
    $entry = TimeEntry::factory()->create(['activity_id' => $otherTask->id]);

    expect(fn () => Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->call('deleteTimeEntry', $entry->id)
    )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(TimeEntry::find($entry->id))->not->toBeNull();
});

test('confirm delete shows confirmation modal', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->call('confirmDelete')
        ->assertSet('showDeleteConfirm', true);
});

test('can delete task', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->call('deleteTask')
        ->assertSet('showModal', false)
        ->assertSet('showDeleteConfirm', false)
        ->assertDispatched('task-updated');

    expect(Activity::find($task->id))->toBeNull();
});

test('deleting task resets component state', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->call('deleteTask')
        ->assertSet('taskId', null)
        ->assertSet('title', '')
        ->assertSet('description', '')
        ->assertSet('timeEntries', []);
});

test('can update time entry notes', function () {
    $task = Activity::factory()->create();
    $entry = TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'notes' => 'Original notes',
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('timeEntries.0.notes', 'Updated notes')
        ->call('saveTask');

    expect($entry->fresh()->notes)->toBe('Updated notes');
});

test('dispatches task-updated event on save', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->call('saveTask')
        ->assertDispatched('task-updated');
});

test('dispatches task-updated event on delete', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->call('deleteTask')
        ->assertDispatched('task-updated');
});

test('validates invalid priority value', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('priority', 'invalid')
        ->call('saveTask')
        ->assertHasErrors(['priority']);
});

test('validates invalid status value', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('status', 'invalid')
        ->call('saveTask')
        ->assertHasErrors(['status']);
});

test('validates project must exist', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('projectId', '99999')
        ->call('saveTask')
        ->assertHasErrors(['projectId']);
});

test('validates estimated minutes must be positive', function () {
    $task = Activity::factory()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('estimatedMinutes', 0)
        ->call('saveTask')
        ->assertHasErrors(['estimatedMinutes']);
});

test('task modal shows session view for session tasks', function () {
    $task = Activity::factory()->doing()->session()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSee('Sessão de Desenvolvimento');
});

test('task modal does not show session view for normal tasks', function () {
    $task = Activity::factory()->doing()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertDontSee('Sessão de Desenvolvimento');
});

test('task modal loads session fields when opening session task', function () {
    $task = Activity::factory()->doing()->create([
        'session_prompt' => 'Implement login feature',
        'session_result' => 'Login implemented with tests',
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSet('sessionPrompt', 'Implement login feature')
        ->assertSet('sessionResult', 'Login implemented with tests');
});

test('task modal saves session fields', function () {
    $task = Activity::factory()->doing()->session()->create();

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->set('sessionPrompt', 'Updated prompt')
        ->set('sessionResult', 'Updated result')
        ->call('saveTask');

    $task->refresh();
    expect($task->session_prompt)->toBe('Updated prompt');
    expect($task->session_result)->toBe('Updated result');
});
