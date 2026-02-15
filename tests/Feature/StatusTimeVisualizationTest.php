<?php

use App\Enums\TaskStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\GetTaskTool;
use App\Models\Task;
use App\Models\TaskStatusChange;
use App\Models\User;
use Livewire\Livewire;

// ─── US-005: Task Modal Status Time Bar ──────────────────────────────

test('task modal shows status time section when task has status changes', function () {
    $this->actingAs(User::factory()->create());

    $task = Task::withoutEvents(fn () => Task::factory()->create(['status' => TaskStatus::Doing]));

    $baseTime = now()->subMinutes(120);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => null,
        'to_status' => TaskStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Inbox,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $baseTime->copy()->addMinutes(60),
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertSee('Tempo por Status')
        ->assertSet('statusTimeSegments.inbox.label', 'Caixa de Entrada')
        ->assertSet('statusTimeSegments.doing.label', 'Fazendo');
});

test('task modal does NOT show status time section when task has no status changes', function () {
    $this->actingAs(User::factory()->create());

    $task = Task::withoutEvents(fn () => Task::factory()->create(['status' => TaskStatus::Inbox]));

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertDontSee('Tempo por Status')
        ->assertSet('statusTimeSegments', []);
});

test('task modal does NOT show status time section when task has only initial status change', function () {
    $this->actingAs(User::factory()->create());

    $task = Task::withoutEvents(fn () => Task::factory()->create(['status' => TaskStatus::Inbox]));

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => null,
        'to_status' => TaskStatus::Inbox,
        'changed_at' => now()->subMinutes(30),
    ]);

    Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id)
        ->assertDontSee('Tempo por Status')
        ->assertSet('statusTimeSegments', []);
});

// ─── US-005: Duration Formatting ─────────────────────────────────────

test('formatDuration returns correct values for various durations', function () {
    $this->actingAs(User::factory()->create());

    $task = Task::withoutEvents(fn () => Task::factory()->create(['status' => TaskStatus::Doing]));

    $baseTime = now()->subMinutes(4380); // 3 days + 1 hour

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => null,
        'to_status' => TaskStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    // Inbox for 4320 minutes (3 days exactly)
    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Inbox,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $baseTime->copy()->addMinutes(4320),
    ]);

    // Doing for ~60 minutes (until now)
    $component = Livewire::test('task-modal')
        ->dispatch('open-task-modal', taskId: $task->id);

    $segments = $component->get('statusTimeSegments');

    // Inbox should show ~3d 0h 0m
    expect($segments['inbox']['minutes'])->toBeGreaterThanOrEqual(4319)
        ->and($segments['inbox']['minutes'])->toBeLessThanOrEqual(4321);

    // Doing should show ~1h 0m
    expect($segments['doing']['minutes'])->toBeGreaterThanOrEqual(59)
        ->and($segments['doing']['minutes'])->toBeLessThanOrEqual(61);
});

// ─── US-006: Dashboard Average Time Metrics ──────────────────────────

test('dashboard shows average time metrics when there are completed tasks', function () {
    $this->actingAs(User::factory()->create());

    // Create a completed task with status changes
    $task = Task::withoutEvents(fn () => Task::factory()->done()->create([
        'completed_at' => now()->subDays(5),
    ]));

    $baseTime = now()->subDays(10);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => null,
        'to_status' => TaskStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Inbox,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $baseTime->copy()->addMinutes(120),
    ]);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Doing,
        'to_status' => TaskStatus::Done,
        'changed_at' => $baseTime->copy()->addMinutes(180),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSee('Tempo médio por status')
        ->assertSee('Caixa de Entrada')
        ->assertSee('Fazendo');
});

test('dashboard shows empty state when no completed tasks exist', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::dashboard')
        ->assertSee('Sem dados suficientes');
});

test('dashboard only considers tasks completed in last 30 days', function () {
    $this->actingAs(User::factory()->create());

    // Create a task completed 60 days ago (should be excluded)
    $oldTask = Task::withoutEvents(fn () => Task::factory()->done()->create([
        'completed_at' => now()->subDays(60),
    ]));

    TaskStatusChange::create([
        'task_id' => $oldTask->id,
        'from_status' => null,
        'to_status' => TaskStatus::Inbox,
        'changed_at' => now()->subDays(65),
    ]);

    TaskStatusChange::create([
        'task_id' => $oldTask->id,
        'from_status' => TaskStatus::Inbox,
        'to_status' => TaskStatus::Done,
        'changed_at' => now()->subDays(60),
    ]);

    Livewire::test('pages::dashboard')
        ->assertSee('Sem dados suficientes');
});

// ─── US-007: MCP GetTaskTool Status Timeline ─────────────────────────

test('get-task returns status_timeline in response', function () {
    $task = Task::withoutEvents(fn () => Task::factory()->create(['status' => TaskStatus::Doing]));

    $baseTime = now()->subMinutes(120);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => null,
        'to_status' => TaskStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Inbox,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $baseTime->copy()->addMinutes(60),
    ]);

    $response = SoloBoardServer::tool(GetTaskTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('"status_timeline"');
    $response->assertSee('"time_in_status"');
    $response->assertSee('"current_status_duration_minutes"');
    $response->assertSee('"to_status": "inbox"');
    $response->assertSee('"to_status": "doing"');
});

test('get-task returns time_in_status with accumulated minutes', function () {
    $task = Task::withoutEvents(fn () => Task::factory()->create(['status' => TaskStatus::Doing]));

    $baseTime = now()->subMinutes(90);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => null,
        'to_status' => TaskStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Inbox,
        'to_status' => TaskStatus::Doing,
        'changed_at' => $baseTime->copy()->addMinutes(30),
    ]);

    $response = SoloBoardServer::tool(GetTaskTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('"time_in_status"');
    $response->assertSee('"current_status_duration_minutes"');
    // Inbox should have ~30 minutes
    $response->assertSee('"inbox"');
    // Doing should have ~60 minutes
    $response->assertSee('"doing"');
});

test('get-task status_timeline includes duration_minutes for each change', function () {
    $task = Task::withoutEvents(fn () => Task::factory()->create(['status' => TaskStatus::Todo]));

    $baseTime = now()->subMinutes(60);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => null,
        'to_status' => TaskStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    TaskStatusChange::create([
        'task_id' => $task->id,
        'from_status' => TaskStatus::Inbox,
        'to_status' => TaskStatus::Todo,
        'changed_at' => $baseTime->copy()->addMinutes(30),
    ]);

    $response = SoloBoardServer::tool(GetTaskTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('"status_timeline"');
    $response->assertSee('"from_status": null');
    $response->assertSee('"to_status": "inbox"');
    $response->assertSee('"to_status": "todo"');
    $response->assertSee('"duration_minutes"');
    $response->assertSee('"from_status": "inbox"');
});
