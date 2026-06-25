<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\AnalyticsService;
use Carbon\Carbon;

// ─── Contribution Heatmap ────────────────────────────────────────────

test('contributionHeatmap returns correct number of days for given weeks', function () {
    $service = new AnalyticsService;

    $result = $service->contributionHeatmap(2);

    // 2 weeks = 14 days + today = 15 days (startOfDay 2 weeks ago through endOfDay today)
    $expectedDays = Carbon::today()->subWeeks(2)->startOfDay()
        ->diffInDays(Carbon::today()) + 1;

    expect($result)->toHaveCount($expectedDays);
});

test('contributionHeatmap calculates hours from time entries correctly', function () {
    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // 2-hour time entry today
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(9),
        'stopped_at' => Carbon::today()->addHours(11),
    ]);

    $result = $service->contributionHeatmap(1);
    $today = collect($result)->firstWhere('date', Carbon::today()->toDateString());

    expect($today['hours'])->toBeGreaterThanOrEqual(1.9)
        ->and($today['hours'])->toBeLessThanOrEqual(2.1);
});

test('contributionHeatmap counts completed tasks per day', function () {
    $service = new AnalyticsService;

    Activity::withoutEvents(fn () => Activity::factory()->count(3)->done()->create([
        'completed_at' => Carbon::today(),
    ]));

    $result = $service->contributionHeatmap(1);
    $today = collect($result)->firstWhere('date', Carbon::today()->toDateString());

    expect($today['tasks_completed'])->toBe(3);
});

test('contributionHeatmap calculates level correctly based on hours', function () {
    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    $yesterday = Carbon::yesterday();
    $twoDaysAgo = Carbon::today()->subDays(2);
    $threeDaysAgo = Carbon::today()->subDays(3);
    $fourDaysAgo = Carbon::today()->subDays(4);

    // Level 1: 30 minutes (<1h)
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => $yesterday->copy()->addHours(9),
        'stopped_at' => $yesterday->copy()->addHours(9)->addMinutes(30),
    ]);

    // Level 2: 1.5 hours (1-2h)
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => $twoDaysAgo->copy()->addHours(9),
        'stopped_at' => $twoDaysAgo->copy()->addHours(10)->addMinutes(30),
    ]);

    // Level 3: 3 hours (2-4h)
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => $threeDaysAgo->copy()->addHours(9),
        'stopped_at' => $threeDaysAgo->copy()->addHours(12),
    ]);

    // Level 4: 5 hours (4h+)
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => $fourDaysAgo->copy()->addHours(8),
        'stopped_at' => $fourDaysAgo->copy()->addHours(13),
    ]);

    $result = collect($service->contributionHeatmap(1));

    expect($result->firstWhere('date', $yesterday->toDateString())['level'])->toBe(1)
        ->and($result->firstWhere('date', $twoDaysAgo->toDateString())['level'])->toBe(2)
        ->and($result->firstWhere('date', $threeDaysAgo->toDateString())['level'])->toBe(3)
        ->and($result->firstWhere('date', $fourDaysAgo->toDateString())['level'])->toBe(4);
});

test('contributionHeatmap returns level 0 when no entries exist', function () {
    $service = new AnalyticsService;

    $result = $service->contributionHeatmap(1);

    $levels = collect($result)->pluck('level')->unique()->all();

    expect($levels)->toBe([0]);
});

test('contributionHeatmap ignores running time entries', function () {
    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    TimeEntry::factory()->running()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(9),
    ]);

    $result = $service->contributionHeatmap(1);
    $today = collect($result)->firstWhere('date', Carbon::today()->toDateString());

    expect($today['hours'])->toBe(0.0)
        ->and($today['level'])->toBe(0);
});

// ─── Cycle Time ──────────────────────────────────────────────────────

test('cycleTime calculates cycle time from first status change to done', function () {
    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'completed_at' => Carbon::today(),
    ]));

    $baseTime = Carbon::today()->subHours(3);

    // First status change: task created (inbox)
    ActivityStatusChange::factory()->create([
        'activity_id' => $task->id,
        'from_status' => null,
        'to_status' => ActivityStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    // Moved to doing
    ActivityStatusChange::factory()->create([
        'activity_id' => $task->id,
        'from_status' => ActivityStatus::Inbox,
        'to_status' => ActivityStatus::Doing,
        'changed_at' => $baseTime->copy()->addHour(),
    ]);

    // Completed (done) — 3 hours after first change
    ActivityStatusChange::factory()->create([
        'activity_id' => $task->id,
        'from_status' => ActivityStatus::Doing,
        'to_status' => ActivityStatus::Done,
        'changed_at' => $baseTime->copy()->addHours(3),
    ]);

    $result = $service->cycleTime('7d');

    expect($result)->toHaveCount(1)
        ->and($result[0]['date'])->toBe(Carbon::today()->toDateString())
        ->and($result[0]['avg_minutes'])->toBeGreaterThanOrEqual(179)
        ->and($result[0]['avg_minutes'])->toBeLessThanOrEqual(181);
});

test('cycleTime filters by project when projectId provided', function () {
    $service = new AnalyticsService;

    $projectA = Project::factory()->create(['name' => 'Project A']);
    $projectB = Project::factory()->create(['name' => 'Project B']);

    // Task in Project A
    $taskA = Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'project_id' => $projectA->id,
        'completed_at' => Carbon::today(),
    ]));

    ActivityStatusChange::factory()->create([
        'activity_id' => $taskA->id,
        'from_status' => null,
        'to_status' => ActivityStatus::Inbox,
        'changed_at' => Carbon::today()->subHours(2),
    ]);

    ActivityStatusChange::factory()->create([
        'activity_id' => $taskA->id,
        'from_status' => ActivityStatus::Inbox,
        'to_status' => ActivityStatus::Done,
        'changed_at' => Carbon::today(),
    ]);

    // Task in Project B
    $taskB = Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'project_id' => $projectB->id,
        'completed_at' => Carbon::today(),
    ]));

    ActivityStatusChange::factory()->create([
        'activity_id' => $taskB->id,
        'from_status' => null,
        'to_status' => ActivityStatus::Inbox,
        'changed_at' => Carbon::today()->subHour(),
    ]);

    ActivityStatusChange::factory()->create([
        'activity_id' => $taskB->id,
        'from_status' => ActivityStatus::Inbox,
        'to_status' => ActivityStatus::Done,
        'changed_at' => Carbon::today(),
    ]);

    $result = $service->cycleTime('7d', $projectA->id);

    expect($result)->toHaveCount(1)
        ->and($result[0]['project_name'])->toBe('Project A');
});

test('cycleTime returns empty when no completed tasks', function () {
    $service = new AnalyticsService;

    Activity::withoutEvents(fn () => Activity::factory()->doing()->create());

    $result = $service->cycleTime('7d');

    expect($result)->toBeEmpty();
});

// ─── Focus Ratio ─────────────────────────────────────────────────────

test('focusRatio returns correct ratio with mix of focus and non-focus entries', function () {
    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // 1-hour focus session
    TimeEntry::factory()->focus()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(9),
        'stopped_at' => Carbon::today()->addHours(10),
    ]);

    // 1-hour non-focus entry
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(11),
        'stopped_at' => Carbon::today()->addHours(12),
        'is_focus_session' => false,
    ]);

    $result = $service->focusRatio('7d');

    // 50% focus ratio
    expect($result)->toBeGreaterThanOrEqual(0.49)
        ->and($result)->toBeLessThanOrEqual(0.51);
});

test('focusRatio returns 0.0 when no entries exist', function () {
    $service = new AnalyticsService;

    expect($service->focusRatio('7d'))->toBe(0.0);
});

test('focusRatio returns 1.0 when all entries are focus sessions', function () {
    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    TimeEntry::factory()->focus()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(9),
        'stopped_at' => Carbon::today()->addHours(10),
    ]);

    TimeEntry::factory()->focus()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(11),
        'stopped_at' => Carbon::today()->addHours(12),
    ]);

    expect($service->focusRatio('7d'))->toBe(1.0);
});

test('focusRatio excludes running entries', function () {
    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // Completed focus session: 1 hour
    TimeEntry::factory()->focus()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(9),
        'stopped_at' => Carbon::today()->addHours(10),
    ]);

    // Running non-focus entry — should be excluded
    TimeEntry::factory()->running()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(11),
        'is_focus_session' => false,
    ]);

    // Only the completed focus session counts, so ratio = 1.0
    expect($service->focusRatio('7d'))->toBe(1.0);
});

// ─── Project Health Scores ───────────────────────────────────────────

test('projectHealthScores scores high for healthy project', function () {
    $service = new AnalyticsService;

    $project = Project::factory()->create();

    // Recent, non-overdue, non-stale task
    $task = Activity::withoutEvents(fn () => Activity::factory()->doing()->create([
        'project_id' => $project->id,
        'updated_at' => Carbon::now(),
    ]));

    // Recent activity
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::now()->subHour(),
        'stopped_at' => Carbon::now(),
    ]);

    $result = $service->projectHealthScores();

    expect($result)->toHaveCount(1)
        ->and($result[0]['score'])->toBeGreaterThanOrEqual(80);
});

test('projectHealthScores penalizes project with overdue tasks', function () {
    $service = new AnalyticsService;

    $project = Project::factory()->create();

    // Create overdue tasks
    Activity::withoutEvents(fn () => Activity::factory()->count(2)->overdue()->create([
        'project_id' => $project->id,
        'updated_at' => Carbon::now(),
    ]));

    // Recent activity to avoid inactivity penalty
    $task = Activity::query()->where('project_id', $project->id)->first();
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::now()->subHour(),
        'stopped_at' => Carbon::now(),
    ]);

    $result = $service->projectHealthScores();

    expect($result)->toHaveCount(1)
        ->and($result[0]['factors']['overdue_tasks'])->toBe(2)
        ->and($result[0]['factors']['overdue_penalty'])->toBeGreaterThan(0)
        ->and($result[0]['score'])->toBeLessThan(100);
});

test('projectHealthScores penalizes project with stale tasks', function () {
    $service = new AnalyticsService;

    $project = Project::factory()->create();

    // Stale task: updated more than 7 days ago
    Activity::withoutEvents(fn () => Activity::factory()->todo()->create([
        'project_id' => $project->id,
        'updated_at' => Carbon::now()->subDays(10),
    ]));

    // Recent activity to avoid inactivity penalty
    ActivityStatusChange::factory()->create([
        'activity_id' => Activity::query()->where('project_id', $project->id)->first()->id,
        'from_status' => ActivityStatus::Inbox,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => Carbon::now(),
    ]);

    $result = $service->projectHealthScores();

    expect($result)->toHaveCount(1)
        ->and($result[0]['factors']['stale_tasks'])->toBe(1)
        ->and($result[0]['factors']['stale_penalty'])->toBeGreaterThan(0)
        ->and($result[0]['score'])->toBeLessThan(100);
});

test('projectHealthScores only includes active projects', function () {
    $service = new AnalyticsService;

    Project::factory()->create(['name' => 'Active Project']);
    Project::factory()->paused()->create(['name' => 'Paused Project']);
    Project::factory()->archived()->create(['name' => 'Archived Project']);

    $result = $service->projectHealthScores();
    $projectNames = collect($result)->pluck('project');

    expect($projectNames)->toContain('Active Project')
        ->and($projectNames)->not->toContain('Paused Project')
        ->and($projectNames)->not->toContain('Archived Project');
});

test('projectHealthScores scores 100 for project with no tasks', function () {
    $service = new AnalyticsService;

    $project = Project::factory()->create();

    $result = $service->projectHealthScores();

    expect($result)->toHaveCount(1)
        ->and($result[0]['score'])->toBe(100)
        ->and($result[0]['factors']['total_tasks'])->toBe(0);
});

// ─── Velocity Trend ──────────────────────────────────────────────────

test('velocityTrend counts completed and created tasks per week', function () {
    $service = new AnalyticsService;

    $weekStart = Carbon::today()->startOfWeek();

    // 3 tasks created this week
    Activity::withoutEvents(fn () => Activity::factory()->count(3)->create([
        'created_at' => $weekStart->copy()->addDay(),
    ]));

    // 2 tasks completed this week
    Activity::withoutEvents(fn () => Activity::factory()->count(2)->done()->create([
        'created_at' => $weekStart->copy()->subWeeks(2),
        'completed_at' => $weekStart->copy()->addDays(2),
    ]));

    $result = $service->velocityTrend(2);
    $thisWeek = collect($result)->firstWhere('week_start', $weekStart->toDateString());

    expect($thisWeek)->not->toBeNull()
        ->and($thisWeek['created_count'])->toBe(3)
        ->and($thisWeek['completed_count'])->toBe(2);
});

test('velocityTrend returns all weeks even with no data', function () {
    $service = new AnalyticsService;

    $result = $service->velocityTrend(4);

    // Should have entries for each week in the period
    expect(count($result))->toBeGreaterThanOrEqual(4);

    foreach ($result as $week) {
        expect($week)->toHaveKeys(['week_start', 'completed_count', 'created_count'])
            ->and($week['completed_count'])->toBe(0)
            ->and($week['created_count'])->toBe(0);
    }
});

// ─── Streaks ─────────────────────────────────────────────────────────

test('streaks calculates current streak from consecutive days', function () {
    Carbon::setTestNow(Carbon::today()->setHour(12));

    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // Activity for 3 consecutive days ending today
    for ($i = 0; $i < 3; $i++) {
        TimeEntry::factory()->create([
            'activity_id' => $task->id,
            'started_at' => Carbon::today()->subDays($i)->addHours(9),
            'stopped_at' => Carbon::today()->subDays($i)->addHours(10),
        ]);
    }

    $result = $service->streaks();

    expect($result['current'])->toBe(3)
        ->and($result['best'])->toBe(3);

    Carbon::setTestNow();
});

test('streaks breaks when day is missed', function () {
    Carbon::setTestNow(Carbon::today()->setHour(12));

    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // Activity today
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(9),
        'stopped_at' => Carbon::today()->addHours(10),
    ]);

    // Activity 2 days ago (gap yesterday)
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->subDays(2)->addHours(9),
        'stopped_at' => Carbon::today()->subDays(2)->addHours(10),
    ]);

    $result = $service->streaks();

    expect($result['current'])->toBe(1)
        ->and($result['best'])->toBe(1);

    Carbon::setTestNow();
});

test('streaks focus streak requires 2+ hours of focus sessions', function () {
    Carbon::setTestNow(Carbon::today()->setHour(12));

    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // Today: 2.5 hours of focus — qualifies
    TimeEntry::factory()->focus()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::today()->addHours(9),
        'stopped_at' => Carbon::today()->addHours(11)->addMinutes(30),
    ]);

    // Yesterday: only 30 minutes of focus — does NOT qualify
    TimeEntry::factory()->focus()->create([
        'activity_id' => $task->id,
        'started_at' => Carbon::yesterday()->addHours(9),
        'stopped_at' => Carbon::yesterday()->addHours(9)->addMinutes(30),
    ]);

    $result = $service->streaks();

    expect($result['focus_current'])->toBe(1);

    Carbon::setTestNow();
});

test('streaks returns zeros when no activity', function () {
    $service = new AnalyticsService;

    $result = $service->streaks();

    expect($result)->toBe([
        'current' => 0,
        'best' => 0,
        'focus_current' => 0,
        'focus_best' => 0,
    ]);
});

test('streaks best streak can be longer than current streak', function () {
    Carbon::setTestNow(Carbon::today()->setHour(12));

    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // Old streak: 5 consecutive days (ending 10 days ago)
    for ($i = 0; $i < 5; $i++) {
        TimeEntry::factory()->create([
            'activity_id' => $task->id,
            'started_at' => Carbon::today()->subDays(10 + $i)->addHours(9),
            'stopped_at' => Carbon::today()->subDays(10 + $i)->addHours(10),
        ]);
    }

    // Current streak: 2 consecutive days (today and yesterday)
    for ($i = 0; $i < 2; $i++) {
        TimeEntry::factory()->create([
            'activity_id' => $task->id,
            'started_at' => Carbon::today()->subDays($i)->addHours(9),
            'stopped_at' => Carbon::today()->subDays($i)->addHours(10),
        ]);
    }

    $result = $service->streaks();

    expect($result['current'])->toBe(2)
        ->and($result['best'])->toBe(5);

    Carbon::setTestNow();
});

// ─── Productivity Patterns ──────────────────────────────────────────

test('productivityPatterns identifies best days and hours', function () {
    $service = new AnalyticsService;

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // Find the most recent Monday and Wednesday
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $wednesday = Carbon::today()->startOfWeek(Carbon::MONDAY)->addDays(2);

    // If these dates are in the future, go back a week
    if ($monday->isFuture()) {
        $monday = $monday->subWeek();
        $wednesday = $wednesday->subWeek();
    }

    // 4 hours on Monday at 10am
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => $monday->copy()->addHours(10),
        'stopped_at' => $monday->copy()->addHours(14),
    ]);

    // 2 hours on Wednesday at 14:00
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => $wednesday->copy()->addHours(14),
        'stopped_at' => $wednesday->copy()->addHours(16),
    ]);

    $result = $service->productivityPatterns(4);

    expect($result)->toHaveKeys(['best_days', 'best_hours', 'avg_by_day', 'avg_by_hour'])
        ->and($result['best_days'])->toContain('Monday')
        ->and($result['best_hours'])->toBeArray()
        ->and(count($result['best_hours']))->toBeLessThanOrEqual(3);
});

test('productivityPatterns returns all days and hours even with no data', function () {
    $service = new AnalyticsService;

    $result = $service->productivityPatterns(4);

    $expectedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    expect(array_keys($result['avg_by_day']))->toBe($expectedDays)
        ->and(count($result['avg_by_hour']))->toBe(24);

    // All averages should be 0 with no data
    foreach ($result['avg_by_day'] as $avg) {
        expect($avg)->toBe(0.0);
    }

    foreach ($result['avg_by_hour'] as $avg) {
        expect($avg)->toBe(0.0);
    }
});

test('productivityPatterns best_days returns top 2 days', function () {
    $service = new AnalyticsService;

    $result = $service->productivityPatterns(4);

    expect($result['best_days'])->toHaveCount(2);
});

test('productivityPatterns best_hours returns top 3 hours', function () {
    $service = new AnalyticsService;

    $result = $service->productivityPatterns(4);

    expect($result['best_hours'])->toHaveCount(3);
});
