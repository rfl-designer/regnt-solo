<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('component renders successfully', function () {
    Livewire::test('command-palette')
        ->assertSuccessful();
});

test('search returns matching tasks', function () {
    $project = Project::factory()->create(['name' => 'API Gateway']);
    Activity::factory()->create(['title' => 'Implementar autenticação', 'project_id' => $project->id]);
    Activity::factory()->create(['title' => 'Corrigir bug no login']);

    Livewire::test('command-palette')
        ->set('search', 'autenticação')
        ->assertSee('Implementar autenticação')
        ->assertDontSee('Corrigir bug no login');
});

test('search returns matching projects', function () {
    Project::factory()->create(['name' => 'API Gateway', 'slug' => 'api-gateway']);
    Project::factory()->create(['name' => 'Mobile App', 'slug' => 'mobile-app']);

    Livewire::test('command-palette')
        ->set('search', 'API')
        ->assertSee('API Gateway')
        ->assertDontSee('Mobile App');
});

test('empty search shows no results', function () {
    Activity::factory()->create(['title' => 'Some task']);

    Livewire::test('command-palette')
        ->set('search', '')
        ->assertDontSee('Some task');
});

test('command prefix shows available commands', function () {
    Livewire::test('command-palette')
        ->set('search', '>')
        ->assertSee('mover')
        ->assertSee('timer')
        ->assertSee('deletar')
        ->assertSee('projeto')
        ->assertSee('prioridade')
        ->assertSee('planejar');
});

test('command mover changes task status', function () {
    $task = Activity::factory()->todo()->create(['title' => 'Minha task']);

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':doing');

    $task->refresh();
    expect($task->status)->toBe(ActivityStatus::Doing);
});

test('command mover to done marks task as done', function () {
    $task = Activity::factory()->todo()->create(['title' => 'Minha task']);

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':done');

    $task->refresh();
    expect($task->status)->toBe(ActivityStatus::Done);
    expect($task->completed_at)->not->toBeNull();
});

test('command timer starts timer for task', function () {
    $task = Activity::factory()->doing()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'timer:'.$task->id);

    expect(TimeEntry::where('activity_id', $task->id)->running()->count())->toBe(1);
});

test('command timer stops running timer', function () {
    $task = Activity::factory()->doing()->create();
    TimeEntry::factory()->running()->create(['activity_id' => $task->id]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'timer:'.$task->id);

    expect(TimeEntry::where('activity_id', $task->id)->running()->count())->toBe(0);
});

test('command deletar removes task', function () {
    $task = Activity::factory()->create(['title' => 'Task para deletar']);

    Livewire::test('command-palette')
        ->call('executeCommand', 'deletar:'.$task->id);

    expect(Activity::find($task->id))->toBeNull();
});

test('command projeto assigns task to project', function () {
    $project = Project::factory()->create(['slug' => 'meu-projeto']);
    $task = Activity::factory()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'projeto:'.$task->id.':'.$project->slug);

    $task->refresh();
    expect($task->project_id)->toBe($project->id);
});

test('command prioridade changes task priority', function () {
    $task = Activity::factory()->create(['priority' => ActivityPriority::Low]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'prioridade:'.$task->id.':urgent');

    $task->refresh();
    expect($task->priority)->toBe(ActivityPriority::Urgent);
});

test('command planejar adds task to today plan', function () {
    $task = Activity::factory()->todo()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'planejar:'.$task->id);

    $plan = DailyPlan::whereDate('date', now()->toDateString())->first();
    expect($plan)->not->toBeNull();
    expect($plan->tasks()->where('activity_id', $task->id)->exists())->toBeTrue();
});

test('command planejar does not duplicate task in plan', function () {
    $task = Activity::factory()->todo()->create();
    $plan = DailyPlan::create(['date' => now()->toDateString()]);
    $plan->tasks()->attach($task->id, ['sort_order' => 0]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'planejar:'.$task->id);

    expect($plan->tasks()->where('activity_id', $task->id)->count())->toBe(1);
});

test('open task dispatches event and resets search', function () {
    $task = Activity::factory()->create();

    Livewire::test('command-palette')
        ->set('search', 'something')
        ->call('openTask', $task->id)
        ->assertSet('search', '')
        ->assertDispatched('open-task-modal', taskId: $task->id);
});

test('open project redirects to project detail', function () {
    $project = Project::factory()->create(['slug' => 'test-project']);

    Livewire::test('command-palette')
        ->set('search', 'something')
        ->call('openProject', $project->slug)
        ->assertSet('search', '')
        ->assertRedirect(route('project.detail', 'test-project'));
});

test('invalid command shows warning', function () {
    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:0');
});

test('mover with invalid status shows warning', function () {
    $task = Activity::factory()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':invalid');

    $task->refresh();
    expect($task->status)->toBe(ActivityStatus::Inbox);
});

test('projeto with invalid slug shows warning', function () {
    $task = Activity::factory()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'projeto:'.$task->id.':nonexistent');

    $task->refresh();
    expect($task->project_id)->toBeNull();
});

test('prioridade with invalid value shows warning', function () {
    $task = Activity::factory()->create(['priority' => ActivityPriority::Medium]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'prioridade:'.$task->id.':invalid');

    $task->refresh();
    expect($task->priority)->toBe(ActivityPriority::Medium);
});
