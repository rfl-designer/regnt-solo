<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Models\Client;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('kanban route requires authentication', function () {
    auth()->logout();

    $this->get(route('kanban'))
        ->assertRedirect(route('login'));
});

test('kanban page renders correctly for authenticated users', function () {
    $this->get(route('kanban'))
        ->assertOk();
});

test('kanban component renders successfully', function () {
    Livewire::test('pages::kanban')
        ->assertSuccessful()
        ->assertSee('Kanban');
});

test('kanban shows tasks in their respective columns', function () {
    $backlogTask = Activity::factory()->issue()->backlog()->create(['title' => 'Backlog task']);
    $todoTask = Activity::factory()->issue()->todo()->create(['title' => 'Todo task']);
    $doingTask = Activity::factory()->issue()->doing()->create(['title' => 'Doing task']);
    $doneTask = Activity::factory()->issue()->done()->create(['title' => 'Done task']);

    Livewire::test('pages::kanban')
        ->assertSee('Backlog task')
        ->assertSee('Todo task')
        ->assertSee('Doing task')
        ->assertSee('Done task');
});

test('kanban does not show inbox tasks', function () {
    Activity::factory()->create(['title' => 'Inbox task']);

    Livewire::test('pages::kanban')
        ->assertDontSee('Inbox task');
});

test('kanban only shows actionable leaf items', function () {
    $issue = Activity::factory()->issue()->backlog()->create(['title' => 'Leaf issue']);
    $atomicEpic = Activity::factory()->epic()->backlog()->create(['title' => 'Atomic epic']);

    $epicContainer = Activity::factory()->epic()->backlog()->create(['title' => 'Epic container']);
    Activity::factory()->issue()->backlog()->create(['parent_id' => $epicContainer->id]);

    $personalTask = Activity::factory()->task()->backlog()->create(['title' => 'Personal task']);

    Livewire::test('pages::kanban')
        ->assertSee('Leaf issue')
        ->assertSee('Atomic epic')
        ->assertDontSee('Epic container')
        ->assertDontSee('Personal task');
});

test('kanban done column only shows tasks completed this week', function () {
    $thisWeekTask = Activity::factory()->issue()->done()->create([
        'title' => 'Done this week',
        'completed_at' => Carbon::now(),
    ]);

    $lastWeekTask = Activity::factory()->issue()->done()->create([
        'title' => 'Done last week',
        'completed_at' => Carbon::now()->subWeek(),
    ]);

    Livewire::test('pages::kanban')
        ->assertSee('Done this week')
        ->assertDontSee('Done last week');
});

test('kanban filters by project', function () {
    $project = Project::factory()->create();
    $projectTask = Activity::factory()->issue()->backlog()->create([
        'title' => 'Project task',
        'project_id' => $project->id,
    ]);
    $otherTask = Activity::factory()->issue()->backlog()->create(['title' => 'Other task']);

    Livewire::test('pages::kanban')
        ->set('filterProject', (string) $project->id)
        ->assertSee('Project task')
        ->assertDontSee('Other task');
});

test('kanban filters by service class', function () {
    Activity::factory()->issue()->backlog()->emergency()->create(['title' => 'Emergency task']);
    Activity::factory()->issue()->backlog()->create([
        'title' => 'Intangible task',
        'service_class' => ServiceClass::Intangible,
    ]);

    Livewire::test('pages::kanban')
        ->set('filterServiceClass', ServiceClass::Emergency->value)
        ->assertSee('Emergency task')
        ->assertDontSee('Intangible task');
});

test('kanban filters overdue tasks', function () {
    Activity::factory()->issue()->overdue()->create(['title' => 'Overdue task']);
    Activity::factory()->issue()->todo()->create(['title' => 'Normal task']);

    Livewire::test('pages::kanban')
        ->set('filterOverdue', true)
        ->assertSee('Overdue task')
        ->assertDontSee('Normal task');
});

test('kanban overdue filter button shows active state when enabled', function () {
    Livewire::test('pages::kanban')
        ->assertSet('filterOverdue', false)
        ->set('filterOverdue', true)
        ->assertSet('filterOverdue', true);
});

test('kanban load more increases limit', function () {
    Livewire::test('pages::kanban')
        ->assertSet('limits.backlog', 20)
        ->call('loadMore', 'backlog')
        ->assertSet('limits.backlog', 40);
});

test('kanban handleSort moves task to new status', function () {
    $task = Activity::factory()->backlog()->create(['title' => 'Move me']);

    Livewire::test('pages::kanban')
        ->call('handleSort', $task->id, 0, ActivityStatus::Todo->value);

    expect($task->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('kanban handleSort to done marks task as done and syncs daily plan', function () {
    $task = Activity::factory()->doing()->create(['title' => 'Complete me']);

    Livewire::test('pages::kanban')
        ->call('handleSort', $task->id, 0, ActivityStatus::Done->value);

    $task->refresh();

    expect($task->status)->toBe(ActivityStatus::Done)
        ->and($task->completed_at)->not->toBeNull();

    $dailyPlan = DailyPlan::whereDate('date', Carbon::today())->first();

    expect($dailyPlan)->not->toBeNull()
        ->and($dailyPlan->tasks()->where('activity_id', $task->id)->exists())->toBeTrue();
});

test('kanban handleSort to done stops running timers', function () {
    $task = Activity::factory()->doing()->create();
    $timeEntry = TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => now()->subHour(),
        'stopped_at' => null,
    ]);

    Livewire::test('pages::kanban')
        ->call('handleSort', $task->id, 0, ActivityStatus::Done->value);

    expect($timeEntry->fresh()->stopped_at)->not->toBeNull();
});

test('kanban shows project info on task cards', function () {
    $project = Project::factory()->create(['name' => 'Meu Projeto', 'emoji' => '🚀']);
    Activity::factory()->backlog()->create(['project_id' => $project->id]);

    Livewire::test('pages::kanban')
        ->assertSee('🚀')
        ->assertSee('Meu Projeto');
});

test('kanban shows the client dot inherited via the project', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    Activity::factory()->issue()->backlog()->create(['project_id' => $project->id]);

    Livewire::test('pages::kanban')
        ->assertSee('Acme Corp');
});

test('kanban shows the client dot for a task linked directly to a client', function () {
    $client = Client::factory()->create(['name' => 'Direct Client Co']);
    Activity::factory()->issue()->backlog()->create(['project_id' => null, 'client_id' => $client->id]);

    Livewire::test('pages::kanban')
        ->assertSee('Direct Client Co');
});

test('kanban shows service class badges', function () {
    Activity::factory()->issue()->backlog()->emergency()->create();

    Livewire::test('pages::kanban')
        ->assertSee('Emergência');
});

test('kanban shows estimate badges', function () {
    Activity::factory()->issue()->backlog()->withEstimate(90)->create();

    Livewire::test('pages::kanban')
        ->assertSee('90m');
});

test('kanban shows overdue badge for overdue tasks', function () {
    $task = Activity::factory()->issue()->overdue()->create();

    Livewire::test('pages::kanban')
        ->assertSee($task->due_date->diffForHumans());
});

test('kanban shows unassigned tasks section', function () {
    Activity::factory()->issue()->backlog()->create([
        'title' => 'Unassigned task',
        'project_id' => null,
    ]);

    Livewire::test('pages::kanban')
        ->assertSee('Sem projeto')
        ->assertSee('Unassigned task');
});

test('kanban listens to task-updated event', function () {
    Livewire::test('pages::kanban')
        ->dispatch('task-updated')
        ->assertSuccessful();
});

test('kanban listens to task-created event', function () {
    Livewire::test('pages::kanban')
        ->dispatch('task-created')
        ->assertSuccessful();
});

test('kanban shows all four column headers', function () {
    Livewire::test('pages::kanban')
        ->assertSee('Backlog')
        ->assertSee('A Fazer')
        ->assertSee('Fazendo')
        ->assertSee('Concluída');
});
