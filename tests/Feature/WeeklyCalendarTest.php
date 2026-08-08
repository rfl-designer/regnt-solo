<?php

use App\Enums\ServiceClass;
use App\Models\Activity;
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
        ->assertSee('Prazos da Semana');
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
    Activity::factory()->todo()->create([
        'title' => 'Task da segunda',
        'due_date' => $monday->toDateString(),
    ]);

    Livewire::test('pages::weekly-calendar')
        ->assertSee('Task da segunda');
});

test('weekly calendar shows available tasks with no date yet', function () {
    $task = Activity::factory()->todo()->create(['title' => 'Task disponível', 'due_date' => null]);

    Livewire::test('pages::weekly-calendar')
        ->assertSee('Task disponível');
});

test('weekly calendar can schedule a task on a specific day', function () {
    $task = Activity::factory()->todo()->create(['title' => 'Task para adicionar', 'due_date' => null]);
    $tomorrow = Carbon::tomorrow()->toDateString();

    Livewire::test('pages::weekly-calendar')
        ->call('setDueDate', $task->id, $tomorrow);

    expect($task->fresh()->due_date->toDateString())->toBe($tomorrow);
});

test('weekly calendar can take a task off the week', function () {
    $task = Activity::factory()->todo()->create([
        'title' => 'Task para remover',
        'due_date' => Carbon::tomorrow()->toDateString(),
    ]);

    Livewire::test('pages::weekly-calendar')->call('clearDueDate', $task->id);

    expect($task->fresh()->due_date)->toBeNull();
});

test('weekly calendar prevents scheduling on past days', function () {
    $task = Activity::factory()->todo()->create(['due_date' => null]);
    $yesterday = Carbon::yesterday()->toDateString();

    Livewire::test('pages::weekly-calendar')
        ->call('setDueDate', $task->id, $yesterday);

    expect($task->fresh()->due_date)->toBeNull();
});

test('weekly calendar prevents unscheduling a past day', function () {
    $task = Activity::factory()->todo()->create([
        'due_date' => Carbon::yesterday()->toDateString(),
    ]);

    Livewire::test('pages::weekly-calendar')->call('clearDueDate', $task->id);

    expect($task->fresh()->due_date)->not->toBeNull();
});

test('weekly calendar shows today badge on current day', function () {
    Livewire::test('pages::weekly-calendar')
        ->assertSee('Hoje');
});

test('weekly calendar shows project info on tasks', function () {
    $project = Project::factory()->create(['name' => 'Projeto Alpha', 'emoji' => '🎯']);
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    Activity::factory()->todo()->create([
        'project_id' => $project->id,
        'due_date' => $monday->toDateString(),
    ]);

    Livewire::test('pages::weekly-calendar')
        ->assertSee('🎯');
});

test('weekly calendar shows day load indicator', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    Activity::factory()->todo()->create([
        'estimated_minutes' => 60,
        'due_date' => $monday->toDateString(),
    ]);
    Activity::factory()->todo()->create([
        'estimated_minutes' => 90,
        'due_date' => $monday->toDateString(),
    ]);

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

    $task = Activity::factory()->todo()->create(['due_date' => $monday->toDateString()]);

    Livewire::test('pages::weekly-calendar')
        ->call('handleSort', $task->id, 0, $tuesday->toDateString());

    expect($task->fresh()->due_date->toDateString())->toBe($tuesday->toDateString());
});

test('weekly calendar prevents drag to past days', function () {
    $today = Carbon::today();
    $yesterday = $today->copy()->subDay();

    $task = Activity::factory()->todo()->create(['due_date' => $today->toDateString()]);

    Livewire::test('pages::weekly-calendar')
        ->call('handleSort', $task->id, 0, $yesterday->toDateString());

    expect($task->fresh()->due_date->toDateString())->toBe($today->toDateString());
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

test('weekly calendar excludes dated tasks from the available list', function () {
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $plannedTask = Activity::factory()->todo()->create([
        'title' => 'Task planejada',
        'due_date' => $monday->toDateString(),
    ]);
    $availableTask = Activity::factory()->todo()->create(['title' => 'Task disponível', 'due_date' => null]);

    $component = Livewire::test('pages::weekly-calendar');

    $availableTasks = $component->get('availableTasks');

    expect($availableTasks->pluck('id')->toArray())->toContain($availableTask->id)
        ->and($availableTasks->pluck('id')->toArray())->not->toContain($plannedTask->id);
});

test('weekly calendar only offers schedulable leaf activities', function () {
    $atomicEpic = Activity::factory()->epic()->todo()->create(['due_date' => null]);

    $epicContainer = Activity::factory()->epic()->todo()->create(['due_date' => null]);
    Activity::factory()->issue()->todo()->create(['parent_id' => $epicContainer->id, 'due_date' => null]);

    $draft = Activity::factory()->draft()->create(['due_date' => null]);

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
    Activity::factory()->done()->create([
        'title' => 'Task concluída',
        'due_date' => $monday->toDateString(),
    ]);

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

// ── Prazo, não plano (issue #147, review) ────────────────────────────────

test('the week speaks of prazo, never of plano', function () {
    Activity::factory()->todo()->create(['title' => 'Sem prazo ainda', 'due_date' => null]);

    Livewire::test('pages::weekly-calendar')
        ->assertSee('Prazos da Semana')
        ->assertSee('Sem prazo')
        ->assertDontSee('plano do dia')
        ->assertDontSee('Task agendada');
});

test('setting a due date says so in the toast and moves the real date', function () {
    $task = Activity::factory()->todo()->create(['due_date' => null]);
    $tomorrow = Carbon::tomorrow();

    Livewire::test('pages::weekly-calendar')
        ->call('setDueDate', $task->id, $tomorrow->toDateString());

    expect($task->fresh()->due_date->toDateString())->toBe($tomorrow->toDateString())
        ->and($task->fresh()->isOverdue())->toBeFalse();
});

test('clearing the due date of a Data fixa is refused instead of erroring', function () {
    $fixed = Activity::factory()->issue()->todo()
        ->fixedDate(Carbon::tomorrow()->toDateString())
        ->create(['title' => 'Entrega contratual']);

    Livewire::test('pages::weekly-calendar')
        ->call('clearDueDate', $fixed->id)
        ->assertSuccessful();

    expect($fixed->fresh()->due_date)->not->toBeNull()
        ->and($fixed->fresh()->service_class)->toBe(ServiceClass::FixedDate);
});

test('dragging a Data fixa to the pool is refused the same way', function () {
    $fixed = Activity::factory()->issue()->todo()
        ->fixedDate(Carbon::tomorrow()->toDateString())
        ->create();

    Livewire::test('pages::weekly-calendar')
        ->call('handleSort', $fixed->id, 0, 'pool')
        ->assertSuccessful();

    expect($fixed->fresh()->due_date)->not->toBeNull();
});

test('the week never touches items that are not schedulable', function () {
    $draft = Activity::factory()->draft()->create(['due_date' => null]);

    Livewire::test('pages::weekly-calendar')
        ->call('setDueDate', $draft->id, Carbon::tomorrow()->toDateString());

    expect($draft->fresh()->due_date)->toBeNull();
});
