<?php

use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('weekly route requires authentication', function () {
    auth()->logout();

    $this->get(route('weekly'))
        ->assertRedirect(route('login'));
});

test('weekly calendar page renders correctly for authenticated users', function () {
    $this->get(route('weekly'))
        ->assertOk();
});

test('weekly calendar component renders successfully', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSuccessful()
        ->assertSee('Calendário Semanal');
});

test('weekly calendar defaults to current week starting on monday', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

    Livewire::test('pages::weekly-calendar')
        ->assertSet('weekStart', $monday);
});

test('weekly calendar shows 5 days by default (monday to friday)', function () {
    $component = Livewire::test('pages::weekly-calendar');

    $days = $component->get('days');

    expect($days)->toHaveCount(5);
});

test('weekly calendar shows 7 days when weekends enabled', function () {
    $component = Livewire::test('pages::weekly-calendar')
        ->set('showWeekends', true);

    $days = $component->get('days');

    expect($days)->toHaveCount(7);
});

test('weekly calendar can toggle weekends', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSet('showWeekends', false)
        ->call('toggleWeekends')
        ->assertSet('showWeekends', true)
        ->call('toggleWeekends')
        ->assertSet('showWeekends', false);
});

test('weekly calendar can navigate to previous week', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $previousMonday = $monday->copy()->subWeek()->toDateString();

    Livewire::test('pages::weekly-calendar')
        ->call('previousWeek')
        ->assertSet('weekStart', $previousMonday);
});

test('weekly calendar can navigate to next week', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $nextMonday = $monday->copy()->addWeek()->toDateString();

    Livewire::test('pages::weekly-calendar')
        ->call('nextWeek')
        ->assertSet('weekStart', $nextMonday);
});

test('weekly calendar can go to today', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);

    Livewire::test('pages::weekly-calendar')
        ->call('nextWeek')
        ->call('goToToday')
        ->assertSet('weekStart', $monday->toDateString());
});

test('weekly calendar shows tasks in their respective days', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $plan = DailyPlan::factory()->create(['date' => $monday]);
    $task = Activity::factory()->todo()->create(['title' => 'Task da segunda']);
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::weekly-calendar')
        ->assertSee('Task da segunda');
});

test('weekly calendar shows available tasks not planned for the week', function () {
    $task = Activity::factory()->todo()->create(['title' => 'Task disponível']);

    Livewire::test('pages::weekly-calendar')
        ->assertSee('Task disponível');
});

test('weekly calendar can add task to a specific day', function () {
    $task = Activity::factory()->todo()->create(['title' => 'Task para adicionar']);
    $tomorrow = Carbon::tomorrow()->toDateString();

    Livewire::test('pages::weekly-calendar')
        ->call('addToPlan', $task->id, $tomorrow);

    $plan = DailyPlan::whereDate('date', $tomorrow)->first();

    expect($plan->tasks()->where('activity_id', $task->id)->exists())->toBeTrue();
});

test('weekly calendar can remove task from a specific day', function () {
    $tomorrow = Carbon::tomorrow();
    $plan = DailyPlan::factory()->create(['date' => $tomorrow]);
    $task = Activity::factory()->todo()->create(['title' => 'Task para remover']);
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::weekly-calendar')
        ->call('removeFromPlan', $task->id, $tomorrow->toDateString());

    expect($plan->fresh()->tasks()->where('activity_id', $task->id)->exists())->toBeFalse();
});

test('weekly calendar prevents adding tasks to past days', function () {
    $task = Activity::factory()->todo()->create();
    $yesterday = Carbon::yesterday()->toDateString();

    Livewire::test('pages::weekly-calendar')
        ->call('addToPlan', $task->id, $yesterday);

    $plan = DailyPlan::whereDate('date', $yesterday)->first();

    expect($plan)->toBeNull();
});

test('weekly calendar prevents removing tasks from past days', function () {
    $yesterday = Carbon::yesterday();
    $plan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Activity::factory()->todo()->create();
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::weekly-calendar')
        ->call('removeFromPlan', $task->id, $yesterday->toDateString());

    expect($plan->fresh()->tasks()->where('activity_id', $task->id)->exists())->toBeTrue();
});

test('weekly calendar shows today badge on current day', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSee('Hoje');
});

test('weekly calendar shows project info on tasks', function () {
    $project = Project::factory()->create(['name' => 'Projeto Alpha', 'emoji' => '🎯']);
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $plan = DailyPlan::factory()->create(['date' => $monday]);
    $task = Activity::factory()->todo()->create(['project_id' => $project->id]);
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::weekly-calendar')
        ->assertSee('🎯');
});

test('weekly calendar shows day load indicator', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $plan = DailyPlan::factory()->create(['date' => $monday]);
    $task1 = Activity::factory()->todo()->create(['estimated_minutes' => 60]);
    $task2 = Activity::factory()->todo()->create(['estimated_minutes' => 90]);
    $plan->tasks()->attach($task1, ['sort_order' => 0]);
    $plan->tasks()->attach($task2, ['sort_order' => 1]);

    Livewire::test('pages::weekly-calendar')
        ->assertSee('2 tasks')
        ->assertSee('2h30m');
});

test('weekly calendar can drag task between days', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $tuesday = $monday->copy()->addDay();

    // Skip if monday is in the past
    if ($monday->isBefore(Carbon::today())) {
        $monday = Carbon::today();
        $tuesday = $monday->copy()->addDay();
    }

    $plan = DailyPlan::factory()->create(['date' => $monday]);
    $task = Activity::factory()->todo()->create();
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::weekly-calendar')
        ->call('handleSort', $task->id, 0, $tuesday->toDateString());

    $tuesdayPlan = DailyPlan::whereDate('date', $tuesday->toDateString())->first();

    expect($tuesdayPlan->tasks()->where('activity_id', $task->id)->exists())->toBeTrue();
});

test('weekly calendar prevents drag to past days', function () {
    $today = Carbon::today();
    $yesterday = $today->copy()->subDay();

    $plan = DailyPlan::factory()->create(['date' => $today]);
    $task = Activity::factory()->todo()->create();
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::weekly-calendar')
        ->call('handleSort', $task->id, 0, $yesterday->toDateString());

    $yesterdayPlan = DailyPlan::whereDate('date', $yesterday->toDateString())->first();

    expect($yesterdayPlan)->toBeNull();
    expect($plan->fresh()->tasks()->where('activity_id', $task->id)->exists())->toBeTrue();
});

test('weekly calendar listens to task-updated event', function () {
    Livewire::test('pages::weekly-calendar')
        ->dispatch('task-updated')
        ->assertSuccessful();
});

test('weekly calendar listens to task-created event', function () {
    Livewire::test('pages::weekly-calendar')
        ->dispatch('task-created')
        ->assertSuccessful();
});

test('weekly calendar week parameter syncs with url', function () {
    $nextMonday = Carbon::today()->startOfWeek(Carbon::MONDAY)->addWeek()->toDateString();

    Livewire::withQueryParams(['week' => $nextMonday])
        ->test('pages::weekly-calendar')
        ->assertSet('weekStart', $nextMonday);
});

test('weekly calendar weekends parameter syncs with url', function () {
    Livewire::withQueryParams(['weekends' => 'true'])
        ->test('pages::weekly-calendar')
        ->assertSet('showWeekends', true);
});

test('weekly calendar handles invalid week date gracefully', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

    Livewire::test('pages::weekly-calendar', ['week' => 'invalid-date'])
        ->assertSet('weekStart', $monday)
        ->assertSuccessful();
});

test('weekly calendar excludes planned tasks from available list', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $plan = DailyPlan::factory()->create(['date' => $monday]);
    $plannedTask = Activity::factory()->todo()->create(['title' => 'Task planejada']);
    $availableTask = Activity::factory()->todo()->create(['title' => 'Task disponível']);
    $plan->tasks()->attach($plannedTask, ['sort_order' => 0]);

    $component = Livewire::test('pages::weekly-calendar');

    $availableTasks = $component->get('availableTasks');

    expect($availableTasks->pluck('id')->toArray())->toContain($availableTask->id)
        ->and($availableTasks->pluck('id')->toArray())->not->toContain($plannedTask->id);
});

test('weekly calendar only offers schedulable leaf activities', function () {
    $atomicEpic = Activity::factory()->epic()->todo()->create();

    $epicContainer = Activity::factory()->epic()->todo()->create();
    Activity::factory()->issue()->todo()->create(['parent_id' => $epicContainer->id]);

    $draft = Activity::factory()->draft()->create();

    $available = Livewire::test('pages::weekly-calendar')
        ->get('availableTasks')
        ->pluck('id')
        ->toArray();

    expect($available)->toContain($atomicEpic->id)
        ->and($available)->not->toContain($epicContainer->id)
        ->and($available)->not->toContain($draft->id);
});

test('weekly calendar shows completed tasks with different styling', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $plan = DailyPlan::factory()->create(['date' => $monday]);
    $task = Activity::factory()->done()->create(['title' => 'Task concluída']);
    $plan->tasks()->attach($task, ['sort_order' => 0, 'completed_at' => now()]);

    Livewire::test('pages::weekly-calendar')
        ->assertSee('Task concluída');
});

test('weekly calendar shows legend section', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSee('Legenda');
});

test('weekly calendar legend shows load color explanations', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSee('Carga:')
        ->assertSee('≤4h')
        ->assertSee('≤6h');
});

test('weekly calendar legend shows icon explanations', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSee('Ícones:')
        ->assertSee('Dia passado')
        ->assertSee('Concluída')
        ->assertSee('Arrastar');
});

test('weekly calendar legend shows toggle explanations', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSee('Toggle:');
});

test('weekly calendar toggle changes state correctly', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSet('showWeekends', false)
        ->call('toggleWeekends')
        ->assertSet('showWeekends', true)
        ->call('toggleWeekends')
        ->assertSet('showWeekends', false);
});

test('weekly calendar toggle shows weekdays label when weekends disabled', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSet('showWeekends', false)
        ->assertSee('Seg-Sex');
});

test('weekly calendar toggle shows full week label when weekends enabled', function () {
    Livewire::test('pages::weekly-calendar')
        ->set('showWeekends', true)
        ->assertSee('Dom-Sáb');
});
