<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Models\Client;
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
        ->assertSee('classe')
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

test('command classe changes task service class', function () {
    $task = Activity::factory()->create(['service_class' => ServiceClass::Intangible]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'classe:'.$task->id.':standard');

    $task->refresh();
    expect($task->service_class)->toBe(ServiceClass::Standard);
});

test('command classe emergency defers to the blocking emergency modal instead of classifying blind', function () {
    $task = Activity::factory()->create(['service_class' => ServiceClass::Intangible]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'classe:'.$task->id.':emergency')
        ->assertDispatched('open-emergency-modal', taskId: $task->id);

    expect($task->fresh()->service_class)->toBe(ServiceClass::Intangible);
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

test('classe with invalid value shows warning', function () {
    $task = Activity::factory()->create(['service_class' => ServiceClass::Standard]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'classe:'.$task->id.':invalid');

    $task->refresh();
    expect($task->service_class)->toBe(ServiceClass::Standard);
});

// -----------------------------------------------------------------------
// Finding 6: moving into the new waiting statuses via the palette must
// not throw an unhandled exception, and help text must list all 8 values.
// -----------------------------------------------------------------------

test('command mover into a client-side wait succeeds and auto-fills waiting_for', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $project = Project::factory()->create(['client_id' => $client->id]);
    $task = Activity::factory()->todo()->create(['project_id' => $project->id]);

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':awaiting_approval')
        ->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(ActivityStatus::AwaitingApproval)
        ->and($task->waiting_for)->toBe('Acme Corp');
});

test('command mover into a client-side wait with no effective client is caught with the canonical message, not a raw exception', function () {
    $task = Activity::factory()->todo()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':awaiting_approval')
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('command mover into the internal wait defers to the blocking mini modal instead of throwing', function () {
    $task = Activity::factory()->doing()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':waiting')
        ->assertDispatched('open-waiting-for-modal', taskId: $task->id, status: 'waiting')
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('mover help text lists all 8 statuses', function () {
    Livewire::test('command-palette')
        ->set('search', '>')
        ->assertSee('awaiting_approval')
        ->assertSee('waiting')
        ->assertSee('awaiting_validation');
});

test('mover with invalid status still mentions all 8 valid values', function () {
    $task = Activity::factory()->create();

    Livewire::test('command-palette')
        ->call('executeCommand', 'mover:'.$task->id.':invalid')
        ->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(ActivityStatus::Inbox);
});
