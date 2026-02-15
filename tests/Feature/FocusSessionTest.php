<?php

use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\StartTimerTool;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\Carbon;

test('focus session creates time entry with is_focus_session true', function () {
    $task = Task::factory()->create();

    TimeEntry::create([
        'task_id' => $task->id,
        'started_at' => now(),
        'is_focus_session' => true,
    ]);

    $this->assertDatabaseHas('time_entries', [
        'task_id' => $task->id,
        'is_focus_session' => true,
    ]);
});

test('focus_rating can be saved on time entry', function () {
    $entry = TimeEntry::factory()->focus()->create();

    $entry->update(['focus_rating' => 4]);

    $this->assertDatabaseHas('time_entries', [
        'id' => $entry->id,
        'is_focus_session' => true,
        'focus_rating' => 4,
    ]);
});

test('focusSessions scope returns only focus entries', function () {
    TimeEntry::factory()->count(3)->create();
    TimeEntry::factory()->focus()->count(2)->create();

    $focusEntries = TimeEntry::focusSessions()->get();

    expect($focusEntries)->toHaveCount(2);
    expect($focusEntries->every(fn ($e) => $e->is_focus_session))->toBeTrue();
});

test('focusForDate scope returns focus entries for specific date', function () {
    $today = Carbon::today();
    $yesterday = Carbon::yesterday();

    TimeEntry::factory()->focus()->create(['started_at' => $today->copy()->setHour(10)]);
    TimeEntry::factory()->focus()->create(['started_at' => $yesterday->copy()->setHour(10)]);
    TimeEntry::factory()->create(['started_at' => $today->copy()->setHour(14)]);

    $todayFocus = TimeEntry::focusForDate($today)->get();

    expect($todayFocus)->toHaveCount(1);
});

test('focusForWeek scope returns focus entries for current week', function () {
    $thisWeek = Carbon::now()->startOfWeek()->addDay();
    $lastWeek = Carbon::now()->subWeek();

    TimeEntry::factory()->focus()->create(['started_at' => $thisWeek]);
    TimeEntry::factory()->focus()->create(['started_at' => $lastWeek]);

    $weekFocus = TimeEntry::focusForWeek()->get();

    expect($weekFocus)->toHaveCount(1);
});

test('focusDurationMinutes returns total focus minutes', function () {
    $today = Carbon::today();

    TimeEntry::factory()->focus()->create([
        'started_at' => $today->copy()->setHour(9),
        'stopped_at' => $today->copy()->setHour(10),
    ]);
    TimeEntry::factory()->focus()->create([
        'started_at' => $today->copy()->setHour(14),
        'stopped_at' => $today->copy()->setHour(15),
    ]);
    // Non-focus entry should not count
    TimeEntry::factory()->create([
        'started_at' => $today->copy()->setHour(11),
        'stopped_at' => $today->copy()->setHour(12),
    ]);

    $totalFocus = TimeEntry::focusDurationMinutes($today);

    expect($totalFocus)->toBe(120.0);
});

test('task totalFocusMinutes returns focus minutes for task', function () {
    $task = Task::factory()->create();

    TimeEntry::factory()->focus()->create([
        'task_id' => $task->id,
        'started_at' => now()->subHours(2),
        'stopped_at' => now()->subHour(),
    ]);
    TimeEntry::factory()->create([
        'task_id' => $task->id,
        'started_at' => now()->subHours(4),
        'stopped_at' => now()->subHours(3),
    ]);

    expect($task->totalFocusMinutes())->toBe(60.0);
});

test('MCP start-timer supports focus parameter', function () {
    $task = Task::factory()->create();

    $response = SoloBoardServer::tool(StartTimerTool::class, [
        'task_id' => $task->id,
        'focus' => true,
    ]);

    $response->assertOk();
    $response->assertSee('"is_focus_session": true');
    $response->assertSee('Focus session started');

    $this->assertDatabaseHas('time_entries', [
        'task_id' => $task->id,
        'is_focus_session' => true,
    ]);
});

test('MCP start-timer defaults to non-focus', function () {
    $task = Task::factory()->create();

    $response = SoloBoardServer::tool(StartTimerTool::class, [
        'task_id' => $task->id,
    ]);

    $response->assertOk();
    $response->assertSee('"is_focus_session": false');

    $this->assertDatabaseHas('time_entries', [
        'task_id' => $task->id,
        'is_focus_session' => false,
    ]);
});

test('factory focus state works correctly', function () {
    $entry = TimeEntry::factory()->focus()->create();

    expect($entry->is_focus_session)->toBeTrue();
    expect($entry->focus_rating)->toBeNull();
});

test('factory focusWithRating state works correctly', function () {
    $entry = TimeEntry::factory()->focusWithRating(5)->create();

    expect($entry->is_focus_session)->toBeTrue();
    expect($entry->focus_rating)->toBe(5);
});
