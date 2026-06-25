<?php

use App\Models\Activity;
use App\Models\DailyPlan;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// ========================================
// Carry-over seletivo (checkboxes)
// ========================================

test('daily planner has selectedYesterdayTasks property', function () {
    Livewire::test('pages::daily-planner')
        ->assertSet('selectedYesterdayTasks', []);
});

test('daily planner can select individual yesterday tasks', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task1 = Activity::factory()->todo()->create(['title' => 'Task 1']);
    $task2 = Activity::factory()->todo()->create(['title' => 'Task 2']);
    $yesterdayPlan->tasks()->attach($task1, ['sort_order' => 0]);
    $yesterdayPlan->tasks()->attach($task2, ['sort_order' => 1]);

    Livewire::test('pages::daily-planner')
        ->set('selectedYesterdayTasks', [$task1->id])
        ->assertSet('selectedYesterdayTasks', [$task1->id]);
});

test('daily planner can carry over only selected tasks', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task1 = Activity::factory()->todo()->create(['title' => 'Task 1']);
    $task2 = Activity::factory()->todo()->create(['title' => 'Task 2']);
    $task3 = Activity::factory()->todo()->create(['title' => 'Task 3']);
    $yesterdayPlan->tasks()->attach($task1, ['sort_order' => 0]);
    $yesterdayPlan->tasks()->attach($task2, ['sort_order' => 1]);
    $yesterdayPlan->tasks()->attach($task3, ['sort_order' => 2]);

    Livewire::test('pages::daily-planner')
        ->set('selectedYesterdayTasks', [$task1->id, $task3->id])
        ->call('carryOverSelected');

    $todayPlan = DailyPlan::whereDate('date', now()->toDateString())->first();

    expect($todayPlan->tasks()->where('activity_id', $task1->id)->exists())->toBeTrue()
        ->and($todayPlan->tasks()->where('activity_id', $task2->id)->exists())->toBeFalse()
        ->and($todayPlan->tasks()->where('activity_id', $task3->id)->exists())->toBeTrue();
});

test('daily planner clears selection after carry over selected', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Activity::factory()->todo()->create();
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->set('selectedYesterdayTasks', [$task->id])
        ->call('carryOverSelected')
        ->assertSet('selectedYesterdayTasks', []);
});

test('daily planner carry over selected does nothing when empty selection', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Activity::factory()->todo()->create();
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->set('selectedYesterdayTasks', [])
        ->call('carryOverSelected');

    $todayPlan = DailyPlan::whereDate('date', now()->toDateString())->first();

    expect($todayPlan?->tasks()->where('activity_id', $task->id)->exists() ?? false)->toBeFalse();
});

test('daily planner carry over selected only works on today', function () {
    $tomorrow = Carbon::tomorrow()->toDateString();
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Activity::factory()->todo()->create();
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner', ['date' => $tomorrow])
        ->set('selectedYesterdayTasks', [$task->id])
        ->call('carryOverSelected');

    $tomorrowPlan = DailyPlan::whereDate('date', $tomorrow)->first();

    expect($tomorrowPlan?->tasks()->where('activity_id', $task->id)->exists() ?? false)->toBeFalse();
});

test('daily planner can select all yesterday tasks', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task1 = Activity::factory()->todo()->create();
    $task2 = Activity::factory()->todo()->create();
    $task3 = Activity::factory()->todo()->create();
    $yesterdayPlan->tasks()->attach($task1, ['sort_order' => 0]);
    $yesterdayPlan->tasks()->attach($task2, ['sort_order' => 1]);
    $yesterdayPlan->tasks()->attach($task3, ['sort_order' => 2]);

    $component = Livewire::test('pages::daily-planner')
        ->call('selectAllYesterday');

    $selected = $component->get('selectedYesterdayTasks');

    expect($selected)->toContain($task1->id)
        ->toContain($task2->id)
        ->toContain($task3->id)
        ->toHaveCount(3);
});

test('daily planner can deselect all yesterday tasks', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task1 = Activity::factory()->todo()->create();
    $task2 = Activity::factory()->todo()->create();
    $yesterdayPlan->tasks()->attach($task1, ['sort_order' => 0]);
    $yesterdayPlan->tasks()->attach($task2, ['sort_order' => 1]);

    Livewire::test('pages::daily-planner')
        ->set('selectedYesterdayTasks', [$task1->id, $task2->id])
        ->call('deselectAllYesterday')
        ->assertSet('selectedYesterdayTasks', []);
});

test('daily planner clears selection after regular carry over', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Activity::factory()->todo()->create();
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->set('selectedYesterdayTasks', [$task->id])
        ->call('carryOver')
        ->assertSet('selectedYesterdayTasks', []);
});

// ========================================
// Badge "Hoje" removido
// ========================================

test('daily planner does not show today badge', function () {
    Livewire::test('pages::daily-planner')
        ->assertDontSeeHtml('<flux:badge color="emerald" icon="check-circle"')
        ->assertDontSee('Hoje</flux:badge>');
});

// ========================================
// Agrupamento colapsável de concluídas
// ========================================

test('daily planner shows collapsible section for completed tasks', function () {
    $plan = DailyPlan::factory()->today()->create();
    $completedTask = Activity::factory()->done()->create(['title' => 'Task concluída']);
    $plan->tasks()->attach($completedTask, ['sort_order' => 0, 'completed_at' => now()]);

    Livewire::test('pages::daily-planner')
        ->assertSee('Concluídas')
        ->assertSee('Task concluída');
});

test('daily planner separates pending and completed tasks', function () {
    $plan = DailyPlan::factory()->today()->create();
    $pendingTask = Activity::factory()->todo()->create(['title' => 'Task pendente']);
    $completedTask = Activity::factory()->done()->create(['title' => 'Task concluída']);
    $plan->tasks()->attach($pendingTask, ['sort_order' => 0]);
    $plan->tasks()->attach($completedTask, ['sort_order' => 1, 'completed_at' => now()]);

    Livewire::test('pages::daily-planner')
        ->assertSee('Task pendente')
        ->assertSee('Task concluída')
        ->assertSee('Concluídas');
});

test('daily planner shows completed count badge in collapsible header', function () {
    $plan = DailyPlan::factory()->today()->create();
    $completedTask1 = Activity::factory()->done()->create(['title' => 'Task 1']);
    $completedTask2 = Activity::factory()->done()->create(['title' => 'Task 2']);
    $plan->tasks()->attach($completedTask1, ['sort_order' => 0, 'completed_at' => now()]);
    $plan->tasks()->attach($completedTask2, ['sort_order' => 1, 'completed_at' => now()]);

    Livewire::test('pages::daily-planner')
        ->assertSee('Concluídas');
});

test('daily planner hides completed section when no completed tasks', function () {
    $plan = DailyPlan::factory()->today()->create();
    $pendingTask = Activity::factory()->todo()->create(['title' => 'Task pendente']);
    $plan->tasks()->attach($pendingTask, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->assertDontSee('Concluídas');
});

test('daily planner renders checkboxes in yesterday tasks section', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task = Activity::factory()->todo()->create(['title' => 'Task de ontem']);
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    Livewire::test('pages::daily-planner')
        ->assertSee('Tasks de Ontem')
        ->assertSee('Selecionar todas');
});

test('daily planner shows selection count when tasks selected', function () {
    $yesterday = Carbon::yesterday()->toDateString();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => $yesterday]);
    $task1 = Activity::factory()->todo()->create();
    $task2 = Activity::factory()->todo()->create();
    $yesterdayPlan->tasks()->attach($task1, ['sort_order' => 0]);
    $yesterdayPlan->tasks()->attach($task2, ['sort_order' => 1]);

    Livewire::test('pages::daily-planner')
        ->set('selectedYesterdayTasks', [$task1->id])
        ->assertSee('1 selecionada(s)');
});
