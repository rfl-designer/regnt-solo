<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\WeeklyReview;
use Carbon\CarbonInterface;

// ─── US-001: Migration, Model e Factory ──────────────────────────────

test('weekly review can be created via factory', function () {
    $review = WeeklyReview::factory()->create();

    expect($review)->toBeInstanceOf(WeeklyReview::class)
        ->and($review->week_start)->toBeInstanceOf(CarbonInterface::class)
        ->and($review->week_end)->toBeInstanceOf(CarbonInterface::class)
        ->and($review->generated_at)->toBeInstanceOf(CarbonInterface::class);
});

test('weekly review factory thisWeek state creates review for current week', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    expect($review->week_start->toDateString())->toBe(now()->startOfWeek()->toDateString())
        ->and($review->week_end->toDateString())->toBe(now()->endOfWeek()->toDateString());
});

test('weekly review factory lastWeek state creates review for previous week', function () {
    $review = WeeklyReview::factory()->lastWeek()->create();

    expect($review->week_start->toDateString())->toBe(now()->subWeek()->startOfWeek()->toDateString())
        ->and($review->week_end->toDateString())->toBe(now()->subWeek()->endOfWeek()->toDateString());
});

test('weekly review factory withReflection state sets reflection text', function () {
    $review = WeeklyReview::factory()->withReflection()->create();

    expect($review->reflection)->not->toBeNull()
        ->and($review->reflection)->toBeString();
});

test('week_start unique constraint prevents duplicate reviews for same week', function () {
    WeeklyReview::factory()->thisWeek()->create();

    expect(fn () => WeeklyReview::factory()->thisWeek()->create())
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('scope forWeek finds review for given date', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();
    WeeklyReview::factory()->lastWeek()->create();

    $found = WeeklyReview::query()->forWeek(now())->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($review->id);
});

test('scope forWeek finds review when date is mid-week', function () {
    $wednesday = now()->startOfWeek()->addDays(2);
    $review = WeeklyReview::factory()->thisWeek()->create();

    $found = WeeklyReview::query()->forWeek($wednesday)->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($review->id);
});

test('scope forWeek returns null when no review exists for week', function () {
    WeeklyReview::factory()->lastWeek()->create();

    $found = WeeklyReview::query()->forWeek(now())->first();

    expect($found)->toBeNull();
});

test('getOrCreateForWeek creates review when it does not exist', function () {
    expect(WeeklyReview::count())->toBe(0);

    $review = WeeklyReview::getOrCreateForWeek(now());

    expect(WeeklyReview::count())->toBe(1)
        ->and($review->week_start->toDateString())->toBe(now()->startOfWeek()->toDateString())
        ->and($review->week_end->toDateString())->toBe(now()->endOfWeek()->toDateString())
        ->and($review->generated_at)->not->toBeNull();
});

test('getOrCreateForWeek returns existing review without creating duplicate', function () {
    $existing = WeeklyReview::factory()->thisWeek()->create();

    $review = WeeklyReview::getOrCreateForWeek(now());

    expect(WeeklyReview::count())->toBe(1)
        ->and($review->id)->toBe($existing->id);
});

test('getOrCreateForWeek normalizes mid-week date to monday', function () {
    $thursday = now()->startOfWeek()->addDays(3);

    $review = WeeklyReview::getOrCreateForWeek($thursday);

    expect($review->week_start->toDateString())->toBe(now()->startOfWeek()->toDateString());
});

test('casts are correctly applied to weekly review attributes', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();
    $review->refresh();

    expect($review->week_start)->toBeInstanceOf(CarbonInterface::class)
        ->and($review->week_end)->toBeInstanceOf(CarbonInterface::class)
        ->and($review->generated_at)->toBeInstanceOf(CarbonInterface::class);
});

// ─── US-002: Computed Methods ────────────────────────────────────────

test('completedTasks returns tasks completed during the week', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $project = Project::factory()->create();
    $doneTask = Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'project_id' => $project->id,
        'completed_at' => now()->startOfWeek()->addDay(),
    ]));

    // Task completed outside the week — should NOT appear
    Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'completed_at' => now()->subWeeks(2),
    ]));

    // Active task — should NOT appear
    Activity::withoutEvents(fn () => Activity::factory()->doing()->create());

    $tasks = $review->completedTasks();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->id)->toBe($doneTask->id)
        ->and($tasks->first()->relationLoaded('project'))->toBeTrue();
});

test('completedTasks returns empty collection when no tasks completed', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    expect($review->completedTasks())->toBeEmpty();
});

test('completedTasks orders by completed_at descending', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $first = Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'completed_at' => now()->startOfWeek()->addDay(),
    ]));
    $second = Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'completed_at' => now()->startOfWeek()->addDays(3),
    ]));

    $tasks = $review->completedTasks();

    expect($tasks)->toHaveCount(2)
        ->and($tasks->first()->id)->toBe($second->id)
        ->and($tasks->last()->id)->toBe($first->id);
});

test('totalHours returns sum of tracked hours during the week', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // 2 hours in the week
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => now()->startOfWeek()->addHours(9),
        'stopped_at' => now()->startOfWeek()->addHours(11),
    ]);

    // 1 hour in the week
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => now()->startOfWeek()->addDays(1)->addHours(9),
        'stopped_at' => now()->startOfWeek()->addDays(1)->addHours(10),
    ]);

    // Outside the week — should NOT count
    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => now()->subWeeks(2),
        'stopped_at' => now()->subWeeks(2)->addHour(),
    ]);

    $hours = $review->totalHours();

    expect($hours)->toBeGreaterThanOrEqual(2.9)
        ->and($hours)->toBeLessThanOrEqual(3.1);
});

test('totalHours returns zero when no time entries exist', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    expect($review->totalHours())->toBe(0.0);
});

test('totalHours ignores running time entries', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $task = Activity::withoutEvents(fn () => Activity::factory()->create());

    // Running entry — should NOT count
    TimeEntry::factory()->running()->create([
        'activity_id' => $task->id,
        'started_at' => now()->startOfWeek()->addHours(9),
    ]);

    expect($review->totalHours())->toBe(0.0);
});

test('hoursByProject groups hours by project', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $projectA = Project::factory()->create(['name' => 'Project A']);
    $projectB = Project::factory()->create(['name' => 'Project B']);

    $taskA = Activity::withoutEvents(fn () => Activity::factory()->create(['project_id' => $projectA->id]));
    $taskB = Activity::withoutEvents(fn () => Activity::factory()->create(['project_id' => $projectB->id]));

    // 2 hours for Project A
    TimeEntry::factory()->create([
        'activity_id' => $taskA->id,
        'started_at' => now()->startOfWeek()->addHours(9),
        'stopped_at' => now()->startOfWeek()->addHours(11),
    ]);

    // 1 hour for Project B
    TimeEntry::factory()->create([
        'activity_id' => $taskB->id,
        'started_at' => now()->startOfWeek()->addHours(14),
        'stopped_at' => now()->startOfWeek()->addHours(15),
    ]);

    $result = $review->hoursByProject();

    expect($result)->toHaveCount(2)
        ->and($result->first()['project']->id)->toBe($projectA->id)
        ->and($result->first()['minutes'])->toBeGreaterThanOrEqual(119)
        ->and($result->last()['project']->id)->toBe($projectB->id)
        ->and($result->last()['minutes'])->toBeGreaterThanOrEqual(59);
});

test('hoursByProject handles tasks without project', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $task = Activity::withoutEvents(fn () => Activity::factory()->create(['project_id' => null]));

    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => now()->startOfWeek()->addHours(9),
        'stopped_at' => now()->startOfWeek()->addHours(10),
    ]);

    $result = $review->hoursByProject();

    expect($result)->toHaveCount(1)
        ->and($result->first()['project'])->toBeNull();
});

test('hoursByProject returns empty when no time entries exist', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    expect($review->hoursByProject())->toBeEmpty();
});

test('staleTasks returns active tasks without status changes in the week', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    // Active task with NO status change in the week — should appear
    $staleTask = Activity::withoutEvents(fn () => Activity::factory()->todo()->create());

    // Active task WITH status change in the week — should NOT appear
    $activeTask = Activity::withoutEvents(fn () => Activity::factory()->doing()->create());
    ActivityStatusChange::factory()->create([
        'activity_id' => $activeTask->id,
        'from_status' => ActivityStatus::Todo,
        'to_status' => ActivityStatus::Doing,
        'changed_at' => now()->startOfWeek()->addDay(),
    ]);

    // Done task — should NOT appear (not active)
    Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'completed_at' => now()->startOfWeek()->addDay(),
    ]));

    // Inbox task — should NOT appear (excluded per spec)
    Activity::withoutEvents(fn () => Activity::factory()->create(['status' => ActivityStatus::Inbox]));

    $stale = $review->staleTasks();

    expect($stale)->toHaveCount(1)
        ->and($stale->first()->id)->toBe($staleTask->id)
        ->and($stale->first()->relationLoaded('project'))->toBeTrue();
});

test('staleTasks returns empty when all tasks had progress', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $task = Activity::withoutEvents(fn () => Activity::factory()->todo()->create());
    ActivityStatusChange::factory()->create([
        'activity_id' => $task->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => now()->startOfWeek()->addDay(),
    ]);

    expect($review->staleTasks())->toBeEmpty();
});

test('statusTimeAverages returns average time per status for completed tasks', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $baseTime = now()->startOfWeek()->addHours(9);

    $task = Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'completed_at' => $baseTime->copy()->addMinutes(180),
    ]));

    ActivityStatusChange::create([
        'activity_id' => $task->id,
        'from_status' => null,
        'to_status' => ActivityStatus::Inbox,
        'changed_at' => $baseTime,
    ]);

    ActivityStatusChange::create([
        'activity_id' => $task->id,
        'from_status' => ActivityStatus::Inbox,
        'to_status' => ActivityStatus::Doing,
        'changed_at' => $baseTime->copy()->addMinutes(60),
    ]);

    ActivityStatusChange::create([
        'activity_id' => $task->id,
        'from_status' => ActivityStatus::Doing,
        'to_status' => ActivityStatus::Done,
        'changed_at' => $baseTime->copy()->addMinutes(180),
    ]);

    $averages = $review->statusTimeAverages();

    expect($averages)->toHaveKey('inbox')
        ->and($averages)->toHaveKey('doing')
        ->and($averages['inbox'])->toBeGreaterThanOrEqual(59)
        ->and($averages['inbox'])->toBeLessThanOrEqual(61)
        ->and($averages['doing'])->toBeGreaterThanOrEqual(119)
        ->and($averages['doing'])->toBeLessThanOrEqual(121);
});

test('statusTimeAverages returns empty array when no completed tasks', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    expect($review->statusTimeAverages())->toBe([]);
});

test('tasksCreatedVsCompleted returns correct counts', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    // 3 tasks created this week
    Activity::withoutEvents(fn () => Activity::factory()->count(3)->create([
        'created_at' => now()->startOfWeek()->addDay(),
    ]));

    // 2 tasks completed this week
    Activity::withoutEvents(fn () => Activity::factory()->count(2)->done()->create([
        'created_at' => now()->subWeeks(2),
        'completed_at' => now()->startOfWeek()->addDays(2),
    ]));

    $result = $review->tasksCreatedVsCompleted();

    expect($result['created'])->toBe(3)
        ->and($result['completed'])->toBe(2);
});

test('tasksCreatedVsCompleted returns zeros when no tasks exist', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    $result = $review->tasksCreatedVsCompleted();

    expect($result['created'])->toBe(0)
        ->and($result['completed'])->toBe(0);
});

test('tasksCreatedVsCompleted does not count tasks outside the week', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    // Task created outside the week
    Activity::withoutEvents(fn () => Activity::factory()->create([
        'created_at' => now()->subWeeks(3),
    ]));

    // Task completed outside the week
    Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'created_at' => now()->subWeeks(3),
        'completed_at' => now()->subWeeks(2),
    ]));

    $result = $review->tasksCreatedVsCompleted();

    expect($result['created'])->toBe(0)
        ->and($result['completed'])->toBe(0);
});

// ─── US-003: Artisan Command ─────────────────────────────────────────

test('soloboard:weekly-review command creates review for current week', function () {
    expect(WeeklyReview::count())->toBe(0);

    $this->artisan('soloboard:weekly-review')
        ->assertExitCode(0)
        ->expectsOutputToContain('Weekly Review gerada com sucesso!');

    expect(WeeklyReview::count())->toBe(1);

    $review = WeeklyReview::first();
    expect($review->week_start->toDateString())->toBe(now()->startOfWeek()->toDateString());
});

test('soloboard:weekly-review command accepts --week option', function () {
    $targetDate = now()->subWeeks(2)->startOfWeek();

    $this->artisan('soloboard:weekly-review', ['--week' => $targetDate->toDateString()])
        ->assertExitCode(0);

    $review = WeeklyReview::first();
    expect($review->week_start->toDateString())->toBe($targetDate->toDateString());
});

test('soloboard:weekly-review command is idempotent', function () {
    $this->artisan('soloboard:weekly-review')->assertExitCode(0);
    $this->artisan('soloboard:weekly-review')->assertExitCode(0);

    expect(WeeklyReview::count())->toBe(1);
});

test('soloboard:weekly-review command fails with invalid date', function () {
    $this->artisan('soloboard:weekly-review', ['--week' => 'invalid-date'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Data invalida');
});

test('soloboard:weekly-review command displays summary with metrics', function () {
    $task = Activity::withoutEvents(fn () => Activity::factory()->done()->create([
        'completed_at' => now()->startOfWeek()->addDay(),
    ]));

    TimeEntry::factory()->create([
        'activity_id' => $task->id,
        'started_at' => now()->startOfWeek()->addHours(9),
        'stopped_at' => now()->startOfWeek()->addHours(11),
    ]);

    $this->artisan('soloboard:weekly-review')
        ->assertExitCode(0)
        ->expectsOutputToContain('Weekly Review gerada com sucesso!')
        ->expectsOutputToContain('Tasks completadas');
});
