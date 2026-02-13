<?php

use App\Models\DailyPlan;
use App\Models\Task;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

test('can create a daily plan', function () {
    $plan = DailyPlan::factory()->today()->create();

    expect($plan->date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($plan->date->toDateString())->toBe(now()->toDateString());
});

test('daily plan has many tasks through pivot', function () {
    $plan = DailyPlan::factory()->create();
    $tasks = Task::factory()->count(3)->create();

    foreach ($tasks as $index => $task) {
        $plan->tasks()->attach($task, ['sort_order' => $index]);
    }

    expect($plan->tasks)->toHaveCount(3)
        ->and($plan->tasks->first()->pivot->sort_order)->toBe(0);
});

test('getOrCreateForDate creates a new plan if none exists', function () {
    $date = Carbon::today();

    $plan = DailyPlan::getOrCreateForDate($date);

    expect($plan)->toBeInstanceOf(DailyPlan::class)
        ->and($plan->date->toDateString())->toBe($date->toDateString())
        ->and(DailyPlan::count())->toBe(1);
});

test('getOrCreateForDate returns existing plan', function () {
    $date = Carbon::today();
    $existing = DailyPlan::factory()->create(['date' => $date->toDateString()]);

    $plan = DailyPlan::getOrCreateForDate($date);

    expect($plan->id)->toBe($existing->id)
        ->and(DailyPlan::count())->toBe(1);
});

test('completionRate returns 0 when no tasks', function () {
    $plan = DailyPlan::factory()->create();

    expect($plan->completionRate())->toBe(0.0);
});

test('completionRate calculates percentage correctly', function () {
    $plan = DailyPlan::factory()->create();
    $tasks = Task::factory()->count(4)->create();

    foreach ($tasks as $index => $task) {
        $completedAt = $index < 3 ? now() : null;
        $plan->tasks()->attach($task, [
            'sort_order' => $index,
            'completed_at' => $completedAt,
        ]);
    }

    expect($plan->completionRate())->toBe(75.0);
});

test('incompleteTasks returns only tasks without completed_at in pivot', function () {
    $plan = DailyPlan::factory()->create();
    $completedTask = Task::factory()->create();
    $incompleteTask = Task::factory()->create();

    $plan->tasks()->attach($completedTask, ['sort_order' => 0, 'completed_at' => now()]);
    $plan->tasks()->attach($incompleteTask, ['sort_order' => 1]);

    $incomplete = $plan->incompleteTasks();

    expect($incomplete)->toHaveCount(1)
        ->and($incomplete->first()->id)->toBe($incompleteTask->id);
});
