<?php

use App\Models\DailyPlan;
use App\Models\Task;
use Carbon\Carbon;

test('can create a daily plan with all fields', function () {
    $plan = DailyPlan::factory()->withNotes()->create([
        'date' => '2026-02-13',
    ]);

    expect($plan)
        ->date->toBeInstanceOf(\Carbon\CarbonImmutable::class)
        ->notes->not->toBeNull();

    expect($plan->date->toDateString())->toBe('2026-02-13');

    $this->assertDatabaseHas('daily_plans', [
        'date' => '2026-02-13 00:00:00',
    ]);
});

test('date must be unique', function () {
    DailyPlan::factory()->create(['date' => '2026-02-13']);

    expect(fn () => DailyPlan::factory()->create(['date' => '2026-02-13']))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('getOrCreateForDate creates a new plan when none exists', function () {
    $plan = DailyPlan::getOrCreateForDate('2026-03-01');

    expect($plan)->toBeInstanceOf(DailyPlan::class);
    expect($plan->date->toDateString())->toBe('2026-03-01');

    $this->assertDatabaseCount('daily_plans', 1);
});

test('getOrCreateForDate returns existing plan for the same date', function () {
    $existing = DailyPlan::factory()->create(['date' => '2026-03-01']);

    $plan = DailyPlan::getOrCreateForDate('2026-03-01');

    expect($plan->id)->toBe($existing->id);

    $this->assertDatabaseCount('daily_plans', 1);
});

test('getOrCreateForDate accepts a Carbon instance', function () {
    $plan = DailyPlan::getOrCreateForDate(Carbon::parse('2026-04-15'));

    expect($plan->date->toDateString())->toBe('2026-04-15');

    $this->assertDatabaseCount('daily_plans', 1);
});

test('can add tasks to a plan with pivot data', function () {
    $plan = DailyPlan::factory()->create();
    $task = Task::factory()->create();

    $plan->tasks()->attach($task->id, [
        'sort_order' => 3,
        'completed_at' => '2026-02-13 14:00:00',
    ]);

    $plan->refresh();

    expect($plan->tasks)->toHaveCount(1);
    expect($plan->tasks->first()->pivot->sort_order)->toBe(3);
    expect($plan->tasks->first()->pivot->completed_at)->toBe('2026-02-13 14:00:00');
});

test('pivot persists sort_order and completed_at', function () {
    $plan = DailyPlan::factory()->create();
    $taskA = Task::factory()->create();
    $taskB = Task::factory()->create();

    $plan->tasks()->attach($taskA->id, ['sort_order' => 1, 'completed_at' => null]);
    $plan->tasks()->attach($taskB->id, ['sort_order' => 2, 'completed_at' => '2026-02-13 10:00:00']);

    $this->assertDatabaseHas('daily_plan_task', [
        'daily_plan_id' => $plan->id,
        'task_id' => $taskA->id,
        'sort_order' => 1,
        'completed_at' => null,
    ]);

    $this->assertDatabaseHas('daily_plan_task', [
        'daily_plan_id' => $plan->id,
        'task_id' => $taskB->id,
        'sort_order' => 2,
        'completed_at' => '2026-02-13 10:00:00',
    ]);
});

test('completionRate returns 0 when no tasks exist', function () {
    $plan = DailyPlan::factory()->create();

    expect($plan->completionRate())->toBe(0.0);
});

test('completionRate returns 0 when no tasks are completed', function () {
    $plan = DailyPlan::factory()->create();
    $tasks = Task::factory()->count(3)->create();

    foreach ($tasks as $task) {
        $plan->tasks()->attach($task->id, ['completed_at' => null]);
    }

    expect($plan->completionRate())->toBe(0.0);
});

test('completionRate returns 50 when half of tasks are completed', function () {
    $plan = DailyPlan::factory()->create();
    $tasks = Task::factory()->count(4)->create();

    $plan->tasks()->attach($tasks[0]->id, ['completed_at' => now()]);
    $plan->tasks()->attach($tasks[1]->id, ['completed_at' => now()]);
    $plan->tasks()->attach($tasks[2]->id, ['completed_at' => null]);
    $plan->tasks()->attach($tasks[3]->id, ['completed_at' => null]);

    expect($plan->completionRate())->toBe(50.0);
});

test('completionRate returns 100 when all tasks are completed', function () {
    $plan = DailyPlan::factory()->create();
    $tasks = Task::factory()->count(3)->create();

    foreach ($tasks as $task) {
        $plan->tasks()->attach($task->id, ['completed_at' => now()]);
    }

    expect($plan->completionRate())->toBe(100.0);
});

test('incompleteTasks returns only tasks without completed_at in pivot', function () {
    $plan = DailyPlan::factory()->create();
    $completedTask = Task::factory()->create(['title' => 'Completed']);
    $incompleteTaskA = Task::factory()->create(['title' => 'Incomplete A']);
    $incompleteTaskB = Task::factory()->create(['title' => 'Incomplete B']);

    $plan->tasks()->attach($completedTask->id, ['completed_at' => now()]);
    $plan->tasks()->attach($incompleteTaskA->id, ['completed_at' => null]);
    $plan->tasks()->attach($incompleteTaskB->id, ['completed_at' => null]);

    $incomplete = $plan->incompleteTasks();

    expect($incomplete)->toHaveCount(2);
    expect($incomplete->pluck('title')->toArray())
        ->toContain('Incomplete A')
        ->toContain('Incomplete B')
        ->not->toContain('Completed');
});

test('deleting a plan cascades to pivot entries', function () {
    $plan = DailyPlan::factory()->create();
    $tasks = Task::factory()->count(3)->create();

    foreach ($tasks as $task) {
        $plan->tasks()->attach($task->id);
    }

    $this->assertDatabaseCount('daily_plan_task', 3);

    $plan->delete();

    $this->assertDatabaseCount('daily_plan_task', 0);
    // Tasks themselves should still exist
    $this->assertDatabaseCount('tasks', 3);
});

test('deleting a task cascades to pivot entries', function () {
    $plan = DailyPlan::factory()->create();
    $taskA = Task::factory()->create();
    $taskB = Task::factory()->create();

    $plan->tasks()->attach([$taskA->id, $taskB->id]);

    $this->assertDatabaseCount('daily_plan_task', 2);

    $taskA->delete();

    $this->assertDatabaseCount('daily_plan_task', 1);
    $this->assertDatabaseHas('daily_plan_task', [
        'task_id' => $taskB->id,
    ]);
});

test('task belongsToMany dailyPlans with pivot data', function () {
    $task = Task::factory()->create();
    $plan = DailyPlan::factory()->create();

    $plan->tasks()->attach($task->id, [
        'sort_order' => 5,
        'completed_at' => '2026-02-13 16:00:00',
    ]);

    $task->refresh();

    expect($task->dailyPlans)->toHaveCount(1);
    expect($task->dailyPlans->first()->pivot->sort_order)->toBe(5);
    expect($task->dailyPlans->first()->pivot->completed_at)->toBe('2026-02-13 16:00:00');
});

test('notes field is nullable', function () {
    $plan = DailyPlan::factory()->create(['notes' => null]);

    expect($plan->notes)->toBeNull();
});

test('factory today state creates plan for today', function () {
    $plan = DailyPlan::factory()->today()->create();

    expect($plan->date->toDateString())->toBe(now()->toDateString());
});

test('factory yesterday state creates plan for yesterday', function () {
    $plan = DailyPlan::factory()->yesterday()->create();

    expect($plan->date->toDateString())->toBe(now()->subDay()->toDateString());
});

test('factory withNotes state creates plan with notes', function () {
    $plan = DailyPlan::factory()->withNotes()->create();

    expect($plan->notes)->not->toBeNull()->toBeString();
});
