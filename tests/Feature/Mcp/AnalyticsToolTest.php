<?php

use App\Enums\ActivityStatus;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\GetAnalyticsTool;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\Carbon;

test('get-analytics returns summary when no metric specified', function () {
    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'period' => '7d',
    ]);

    $response->assertOk();
    $response->assertSee('streaks');
    $response->assertSee('focus_ratio');
    $response->assertSee('health_scores');
});

test('get-analytics returns specific metric when metric is provided', function () {
    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'period' => '7d',
        'metric' => 'streaks',
    ]);

    $response->assertOk();
    $response->assertSee('"metric": "streaks"');
    $response->assertSee('current');
    $response->assertSee('best');
    $response->assertSee('focus_current');
    $response->assertSee('focus_best');
});

test('get-analytics returns heatmap metric', function () {
    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(9),
        'stopped_at' => Carbon::today()->addHours(11),
    ]);

    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'period' => '7d',
        'metric' => 'heatmap',
    ]);

    $response->assertOk();
    $response->assertSee('"metric": "heatmap"');
    $response->assertSee('level');
    $response->assertSee('hours');
});

test('get-analytics returns focus_ratio metric', function () {
    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    TimeEntry::factory()->focus()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(9),
        'stopped_at' => Carbon::today()->addHours(11),
    ]);

    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'period' => '7d',
        'metric' => 'focus_ratio',
    ]);

    $response->assertOk();
    $response->assertSee('"metric": "focus_ratio"');
});

test('get-analytics returns health_scores metric', function () {
    Project::factory()->create(['name' => 'Test Project']);

    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'metric' => 'health_scores',
    ]);

    $response->assertOk();
    $response->assertSee('"metric": "health_scores"');
    $response->assertSee('Test Project');
});

test('get-analytics returns velocity metric', function () {
    Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'completed_at' => Carbon::today(),
    ]));

    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'period' => '4w',
        'metric' => 'velocity',
    ]);

    $response->assertOk();
    $response->assertSee('"metric": "velocity"');
    $response->assertSee('week_start');
    $response->assertSee('completed_count');
});

test('get-analytics returns patterns metric', function () {
    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'period' => '4w',
        'metric' => 'patterns',
    ]);

    $response->assertOk();
    $response->assertSee('"metric": "patterns"');
    $response->assertSee('best_days');
    $response->assertSee('best_hours');
});

test('get-analytics returns cycle_time metric', function () {
    $task = Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'completed_at' => Carbon::today(),
    ]));

    ActivityStatusChange::factory()->create([
        'activity_id' => $task->id,
        'from_status' => null,
        'to_status' => ActivityStatus::Inbox,
        'changed_at' => Carbon::today()->subHours(3),
    ]);

    ActivityStatusChange::factory()->create([
        'activity_id' => $task->id,
        'from_status' => ActivityStatus::Inbox,
        'to_status' => ActivityStatus::Done,
        'changed_at' => Carbon::today(),
    ]);

    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'period' => '7d',
        'metric' => 'cycle_time',
    ]);

    $response->assertOk();
    $response->assertSee('"metric": "cycle_time"');
});

test('get-analytics defaults period to 7d', function () {
    $response = SoloBoardServer::tool(GetAnalyticsTool::class, []);

    $response->assertOk();
    $response->assertSee('"period": "7d"');
});

test('get-analytics validates invalid metric', function () {
    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'metric' => 'invalid_metric',
    ]);

    $response->assertHasErrors();
});

test('get-analytics validates invalid period', function () {
    $response = SoloBoardServer::tool(GetAnalyticsTool::class, [
        'period' => 'invalid',
    ]);

    $response->assertHasErrors();
});
