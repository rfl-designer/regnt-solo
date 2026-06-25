<?php

use App\Models\Activity;
use App\Models\DailyPlan;

test('task with due_date today is auto-added to daily plan on creation', function () {
    $task = Activity::factory()->create([
        'due_date' => now()->toDateString(),
    ]);

    $plan = DailyPlan::query()->whereDate('date', now()->toDateString())->first();

    expect($plan)->not->toBeNull()
        ->and($plan->tasks)->toHaveCount(1)
        ->and($plan->tasks->first()->id)->toBe($task->id);
});

test('task with due_date tomorrow is not auto-added to daily plan', function () {
    $task = Activity::factory()->create([
        'due_date' => now()->addDay()->toDateString(),
    ]);

    $plan = DailyPlan::query()->whereDate('date', now()->toDateString())->first();

    expect($plan)->toBeNull();
});

test('task without due_date is not auto-added to daily plan', function () {
    $task = Activity::factory()->create([
        'due_date' => null,
    ]);

    $plan = DailyPlan::query()->whereDate('date', now()->toDateString())->first();

    expect($plan)->toBeNull();
});

test('task is not duplicated if already in daily plan', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Activity::factory()->create([
        'due_date' => now()->toDateString(),
    ]);

    expect($plan->tasks)->toHaveCount(1);

    // Tentar adicionar manualmente novamente
    $plan->tasks()->syncWithoutDetaching([$task->id => ['sort_order' => 99]]);

    $plan->refresh();
    expect($plan->tasks)->toHaveCount(1);
});

test('updating due_date to today adds task to daily plan', function () {
    $task = Activity::factory()->create([
        'due_date' => now()->addDays(5)->toDateString(),
    ]);

    // Não deve existir plano ainda
    expect(DailyPlan::query()->whereDate('date', now()->toDateString())->exists())->toBeFalse();

    // Atualizar para hoje
    $task->update(['due_date' => now()->toDateString()]);

    $plan = DailyPlan::query()->whereDate('date', now()->toDateString())->first();

    expect($plan)->not->toBeNull()
        ->and($plan->tasks)->toHaveCount(1)
        ->and($plan->tasks->first()->id)->toBe($task->id);
});

test('updating due_date from today to another date does not remove from plan', function () {
    $task = Activity::factory()->create([
        'due_date' => now()->toDateString(),
    ]);

    $plan = DailyPlan::query()->whereDate('date', now()->toDateString())->first();
    expect($plan->tasks)->toHaveCount(1);

    // Atualizar para amanhã
    $task->update(['due_date' => now()->addDay()->toDateString()]);

    $plan->refresh();
    // A task permanece no plano (usuário pode remover manualmente se quiser)
    expect($plan->tasks)->toHaveCount(1);
});

test('multiple tasks with due_date today are all added with correct sort_order', function () {
    $task1 = Activity::factory()->create(['due_date' => now()->toDateString()]);
    $task2 = Activity::factory()->create(['due_date' => now()->toDateString()]);
    $task3 = Activity::factory()->create(['due_date' => now()->toDateString()]);

    $plan = DailyPlan::query()->whereDate('date', now()->toDateString())->first();

    expect($plan->tasks)->toHaveCount(3);

    $sortOrders = $plan->tasks->pluck('pivot.sort_order')->toArray();
    expect($sortOrders)->toBe([0, 1, 2]);
});

test('task added to existing daily plan with tasks gets correct sort_order', function () {
    $plan = DailyPlan::factory()->today()->create();
    $existingTask = Activity::factory()->create();
    $plan->tasks()->attach($existingTask, ['sort_order' => 5]);

    $newTask = Activity::factory()->create(['due_date' => now()->toDateString()]);

    $plan->refresh();
    expect($plan->tasks)->toHaveCount(2);

    $newTaskPivot = $plan->tasks->where('id', $newTask->id)->first()->pivot;
    expect($newTaskPivot->sort_order)->toBe(6);
});

test('marking task as done updates pivot completed_at in daily plan', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Activity::factory()->todo()->create();
    $plan->tasks()->attach($task, ['sort_order' => 0]);

    expect($plan->tasks()->first()->pivot->completed_at)->toBeNull();

    $task->update(['status' => \App\Enums\ActivityStatus::Done, 'completed_at' => now()]);

    $plan->refresh();
    expect($plan->tasks()->first()->pivot->completed_at)->not->toBeNull();
});

test('reopening done task clears pivot completed_at in daily plan', function () {
    $plan = DailyPlan::factory()->today()->create();
    $task = Activity::factory()->done()->create();
    $plan->tasks()->attach($task, ['sort_order' => 0, 'completed_at' => now()]);

    expect($plan->tasks()->first()->pivot->completed_at)->not->toBeNull();

    $task->update(['status' => \App\Enums\ActivityStatus::Doing, 'completed_at' => null]);

    $plan->refresh();
    expect($plan->tasks()->first()->pivot->completed_at)->toBeNull();
});

test('marking task as done does not affect other daily plans', function () {
    $todayPlan = DailyPlan::factory()->today()->create();
    $yesterdayPlan = DailyPlan::factory()->create(['date' => now()->subDay()]);
    $task = Activity::factory()->todo()->create();

    $todayPlan->tasks()->attach($task, ['sort_order' => 0]);
    $yesterdayPlan->tasks()->attach($task, ['sort_order' => 0]);

    $task->update(['status' => \App\Enums\ActivityStatus::Done, 'completed_at' => now()]);

    $todayPlan->refresh();
    $yesterdayPlan->refresh();

    // Só o plano de hoje deve ser atualizado
    expect($todayPlan->tasks()->first()->pivot->completed_at)->not->toBeNull()
        ->and($yesterdayPlan->tasks()->first()->pivot->completed_at)->toBeNull();
});

test('marking task as done when not in daily plan does not fail', function () {
    $task = Activity::factory()->todo()->create();

    // Nenhum plano existe
    expect(DailyPlan::count())->toBe(0);

    // Não deve lançar exceção
    $task->update(['status' => \App\Enums\ActivityStatus::Done, 'completed_at' => now()]);

    expect($task->fresh()->status)->toBe(\App\Enums\ActivityStatus::Done);
});
