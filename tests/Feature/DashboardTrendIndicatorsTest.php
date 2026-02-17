<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskStatusChange;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('averageTimeByStatus returns empty array when no completed tasks', function () {
    $component = Livewire::test('pages::dashboard');

    expect($component->instance()->averageTimeByStatus)->toBe([]);
});

test('averageTimeByStatus includes trend data when previous period has data', function () {
    $now = Carbon::now();

    $currentTask = Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => $now->copy()->subDays(10),
    ]);

    TaskStatusChange::create([
        'task_id' => $currentTask->id,
        'status' => TaskStatus::Doing,
        'from_status' => null,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $now->copy()->subDays(12),
    ]);

    TaskStatusChange::create([
        'task_id' => $currentTask->id,
        'status' => TaskStatus::Done,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $now->copy()->subDays(10),
    ]);

    $previousTask = Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => $now->copy()->subDays(40),
    ]);

    TaskStatusChange::create([
        'task_id' => $previousTask->id,
        'status' => TaskStatus::Doing,
        'from_status' => null,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $now->copy()->subDays(50),
    ]);

    TaskStatusChange::create([
        'task_id' => $previousTask->id,
        'status' => TaskStatus::Done,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $now->copy()->subDays(40),
    ]);

    $component = Livewire::test('pages::dashboard');

    $averages = $component->instance()->averageTimeByStatus;

    expect($averages)->toHaveKey(TaskStatus::Doing->value);

    $doingData = $averages[TaskStatus::Doing->value];

    expect($doingData)->toHaveKeys([
        'label',
        'avg_minutes',
        'formatted',
        'color',
        'hex_color',
        'prev_avg_minutes',
        'prev_formatted',
        'trend_direction',
        'trend_pct',
    ]);

    expect($doingData['prev_avg_minutes'])->not->toBeNull();
    expect($doingData['trend_direction'])->toBeIn(['up', 'down', 'neutral']);
});

test('averageTimeByStatus returns null trend data when no previous period data', function () {
    $now = Carbon::now();

    $task = Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => $now->copy()->subDays(10),
    ]);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'status' => TaskStatus::Doing,
        'from_status' => null,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $now->copy()->subDays(12),
    ]);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'status' => TaskStatus::Done,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $now->copy()->subDays(10),
    ]);

    $component = Livewire::test('pages::dashboard');

    $averages = $component->instance()->averageTimeByStatus;
    $doingData = $averages[TaskStatus::Doing->value];

    expect($doingData['prev_avg_minutes'])->toBeNull();
    expect($doingData['prev_formatted'])->toBeNull();
    expect($doingData['trend_direction'])->toBeNull();
    expect($doingData['trend_pct'])->toBeNull();
});

test('trend direction is down (green) when current time is less than previous', function () {
    $now = Carbon::now();

    $currentTask = Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => $now->copy()->subDays(5),
    ]);

    TaskStatusChange::create([
        'task_id' => $currentTask->id,
        'status' => TaskStatus::Doing,
        'from_status' => null,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $now->copy()->subDays(6),
    ]);

    TaskStatusChange::create([
        'task_id' => $currentTask->id,
        'status' => TaskStatus::Done,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $now->copy()->subDays(5),
    ]);

    $previousTask = Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => $now->copy()->subDays(40),
    ]);

    TaskStatusChange::create([
        'task_id' => $previousTask->id,
        'status' => TaskStatus::Doing,
        'from_status' => null,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $now->copy()->subDays(50),
    ]);

    TaskStatusChange::create([
        'task_id' => $previousTask->id,
        'status' => TaskStatus::Done,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $now->copy()->subDays(40),
    ]);

    $component = Livewire::test('pages::dashboard');

    $averages = $component->instance()->averageTimeByStatus;
    $doingData = $averages[TaskStatus::Doing->value];

    expect($doingData['trend_direction'])->toBe('down');
    expect($doingData['trend_pct'])->toBeLessThan(0);
});

test('trend direction is up (red) when current time is greater than previous', function () {
    $now = Carbon::now();

    $currentTask = Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => $now->copy()->subDays(5),
    ]);

    TaskStatusChange::create([
        'task_id' => $currentTask->id,
        'status' => TaskStatus::Doing,
        'from_status' => null,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $now->copy()->subDays(15),
    ]);

    TaskStatusChange::create([
        'task_id' => $currentTask->id,
        'status' => TaskStatus::Done,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $now->copy()->subDays(5),
    ]);

    $previousTask = Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => $now->copy()->subDays(40),
    ]);

    TaskStatusChange::create([
        'task_id' => $previousTask->id,
        'status' => TaskStatus::Doing,
        'from_status' => null,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $now->copy()->subDays(41),
    ]);

    TaskStatusChange::create([
        'task_id' => $previousTask->id,
        'status' => TaskStatus::Done,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $now->copy()->subDays(40),
    ]);

    $component = Livewire::test('pages::dashboard');

    $averages = $component->instance()->averageTimeByStatus;
    $doingData = $averages[TaskStatus::Doing->value];

    expect($doingData['trend_direction'])->toBe('up');
    expect($doingData['trend_pct'])->toBeGreaterThan(0);
});

test('dashboard renders trend indicators in the view', function () {
    $now = Carbon::now();

    $currentTask = Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => $now->copy()->subDays(5),
    ]);

    TaskStatusChange::create([
        'task_id' => $currentTask->id,
        'status' => TaskStatus::Doing,
        'from_status' => null,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $now->copy()->subDays(6),
    ]);

    TaskStatusChange::create([
        'task_id' => $currentTask->id,
        'status' => TaskStatus::Done,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $now->copy()->subDays(5),
    ]);

    $previousTask = Task::factory()->create([
        'status' => TaskStatus::Done,
        'completed_at' => $now->copy()->subDays(40),
    ]);

    TaskStatusChange::create([
        'task_id' => $previousTask->id,
        'status' => TaskStatus::Doing,
        'from_status' => null,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $now->copy()->subDays(50),
    ]);

    TaskStatusChange::create([
        'task_id' => $previousTask->id,
        'status' => TaskStatus::Done,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $now->copy()->subDays(40),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSee('Tempo médio por status')
        ->assertSee('Fazendo')
        ->assertSee('%');
});
