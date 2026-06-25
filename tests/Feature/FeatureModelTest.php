<?php

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Feature Model', function () {
    test('can create a feature', function () {
        $feature = Activity::factory()->epic()->create([
            'title' => 'Auth com 2FA',
            'slug' => 'auth-com-2fa',
            'priority' => ActivityPriority::High,
        ]);

        expect($feature)->toBeInstanceOf(Activity::class)
            ->and($feature->title)->toBe('Auth com 2FA')
            ->and($feature->priority)->toBe(ActivityPriority::High)
            ->and($feature->slug)->toBe('auth-com-2fa');
    });

    test('auto generates slug from title', function () {
        $feature = Activity::factory()->epic()->create([
            'title' => 'Dashboard de Analytics',
            'slug' => null,
        ]);

        expect($feature->slug)->toBe('dashboard-de-analytics');
    });

    test('belongs to a project', function () {
        $project = Project::factory()->create();
        $feature = Activity::factory()->epic()->create(['project_id' => $project->id]);

        expect($feature->project)->toBeInstanceOf(Project::class)
            ->and($feature->project->id)->toBe($project->id);
    });

    test('can be created without a project', function () {
        $feature = Activity::factory()->epic()->create(['project_id' => null]);

        expect($feature->project)->toBeNull();
    });

    test('has many tasks', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->count(3)->create(['parent_id' => $feature->id]);

        expect($feature->children)->toHaveCount(3);
    });

    test('has many time entries', function () {
        $feature = Activity::factory()->epic()->create();
        TimeEntry::factory()->count(2)->create(['activity_id' => $feature->id]);

        expect($feature->timeEntries)->toHaveCount(2);
    });
});

// ADR: epic()->create() starts with status=Backlog (Draft status retired).
// Tests that previously expected 'draft' now expect 'backlog'.
describe('Feature Status (persisted)', function () {
    test('status defaults to backlog on creation', function () {
        $feature = Activity::factory()->epic()->create();

        expect($feature->status)->toBe(ActivityStatus::Backlog);
        $this->assertDatabaseHas('activities', ['id' => $feature->id, 'status' => 'backlog']);
    });

    test('status can be set to any ActivityStatus value', function () {
        $feature = Activity::factory()->epic()->create(['status' => ActivityStatus::Doing]);

        expect($feature->status)->toBe(ActivityStatus::Doing);
        $this->assertDatabaseHas('activities', ['id' => $feature->id, 'status' => 'doing']);
    });

    test('status persists when updated', function () {
        $feature = Activity::factory()->epic()->create(['status' => ActivityStatus::Backlog]);
        $feature->update(['status' => ActivityStatus::Todo]);
        $feature->refresh();

        expect($feature->status)->toBe(ActivityStatus::Todo);
        $this->assertDatabaseHas('activities', ['id' => $feature->id, 'status' => 'todo']);
    });

    test('status is independent of tasks', function () {
        // Feature manually set to Doing, but all tasks are Done → status stays Doing
        $feature = Activity::factory()->epic()->create(['status' => ActivityStatus::Doing]);
        Activity::factory()->done()->create(['parent_id' => $feature->id]);
        Activity::factory()->done()->create(['parent_id' => $feature->id]);

        $feature->refresh();

        expect($feature->status)->toBe(ActivityStatus::Doing)
            ->and($feature->progress)->toBe(100);
    });
});

describe('Feature Progress', function () {
    test('progress is 0 when no tasks', function () {
        $feature = Activity::factory()->epic()->create();

        expect($feature->progress)->toBe(0);
    });

    test('progress is 0 when no tasks are done', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->count(3)->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        expect($feature->progress)->toBe(0);
    });

    test('progress is 50 when half tasks are done', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->done()->count(2)->create(['parent_id' => $feature->id]);
        Activity::factory()->count(2)->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        expect($feature->progress)->toBe(50);
    });

    test('progress is 100 when all tasks are done', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->done()->count(3)->create(['parent_id' => $feature->id]);

        expect($feature->progress)->toBe(100);
    });
});

describe('Feature Total Time', function () {
    test('total time is 0 when no time entries', function () {
        $feature = Activity::factory()->epic()->create();

        expect($feature->total_time)->toBe(0.0);
    });

    test('total time sums completed time entries', function () {
        $feature = Activity::factory()->epic()->create();
        TimeEntry::factory()->create([
            'activity_id' => $feature->id,
            'started_at' => now()->subMinutes(60),
            'stopped_at' => now(),
        ]);
        TimeEntry::factory()->create([
            'activity_id' => $feature->id,
            'started_at' => now()->subMinutes(30),
            'stopped_at' => now(),
        ]);

        expect($feature->total_time)->toBeGreaterThanOrEqual(89.0)
            ->and($feature->total_time)->toBeLessThanOrEqual(91.0);
    });

    test('total time excludes running entries', function () {
        $feature = Activity::factory()->epic()->create();
        TimeEntry::factory()->create([
            'activity_id' => $feature->id,
            'started_at' => now()->subMinutes(60),
            'stopped_at' => now(),
        ]);
        TimeEntry::factory()->create([
            'activity_id' => $feature->id,
            'started_at' => now()->subMinutes(30),
            'stopped_at' => null,
        ]);

        expect($feature->total_time)->toBeGreaterThanOrEqual(59.0)
            ->and($feature->total_time)->toBeLessThanOrEqual(61.0);
    });
});

describe('Feature Timer', function () {
    test('is not running when no time entries', function () {
        $feature = Activity::factory()->epic()->create();

        expect($feature->isRunning())->toBeFalse();
    });

    test('is running when has a running time entry', function () {
        $feature = Activity::factory()->epic()->create();
        TimeEntry::factory()->create([
            'activity_id' => $feature->id,
            'stopped_at' => null,
        ]);

        expect($feature->isRunning())->toBeTrue();
    });

    test('can start a timer', function () {
        $feature = Activity::factory()->epic()->create();

        $entry = $feature->startTimer();

        expect($entry)->toBeInstanceOf(TimeEntry::class)
            ->and($entry->activity_id)->toBe($feature->id)
            ->and($entry->stopped_at)->toBeNull();
    });

    test('starting timer stops other running timers', function () {
        $feature1 = Activity::factory()->epic()->create();
        $feature2 = Activity::factory()->epic()->create();

        $entry1 = $feature1->startTimer();
        $entry2 = $feature2->startTimer();

        $entry1->refresh();

        expect($entry1->stopped_at)->not->toBeNull()
            ->and($entry2->stopped_at)->toBeNull();
    });

    test('can start a focus timer', function () {
        $feature = Activity::factory()->epic()->create();

        $entry = $feature->startTimer(focus: true);

        expect($entry->is_focus_session)->toBeTrue();
    });

    test('can stop a timer', function () {
        $feature = Activity::factory()->epic()->create();
        $entry = $feature->startTimer();

        $stoppedEntry = $feature->stopTimer('Work completed');

        expect($stoppedEntry)->not->toBeNull()
            ->and($stoppedEntry->stopped_at)->not->toBeNull()
            ->and($stoppedEntry->notes)->toBe('Work completed');
    });

    test('stopTimer returns null when no running entry', function () {
        $feature = Activity::factory()->epic()->create();

        $result = $feature->stopTimer();

        expect($result)->toBeNull();
    });

    test('stopTimer can save notes as null', function () {
        $feature = Activity::factory()->epic()->create();
        $entry = $feature->startTimer();

        $stoppedEntry = $feature->stopTimer();

        expect($stoppedEntry)->not->toBeNull()
            ->and($stoppedEntry->stopped_at)->not->toBeNull()
            ->and($stoppedEntry->notes)->toBeNull();
    });

    test('runningEntry returns the running time entry', function () {
        $feature = Activity::factory()->epic()->create();
        $entry = $feature->startTimer();

        $runningEntry = $feature->runningEntry();

        expect($runningEntry)->not->toBeNull()
            ->and($runningEntry->id)->toBe($entry->id);
    });

    test('runningEntry returns null when no running entry', function () {
        $feature = Activity::factory()->epic()->create();

        $runningEntry = $feature->runningEntry();

        expect($runningEntry)->toBeNull();
    });
});

describe('Feature Helper Methods', function () {
    test('counts completed tasks', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->done()->count(2)->create(['parent_id' => $feature->id]);
        Activity::factory()->count(3)->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        expect($feature->completedTasksCount())->toBe(2);
    });

    test('counts total tasks', function () {
        $feature = Activity::factory()->epic()->create();
        Activity::factory()->done()->count(2)->create(['parent_id' => $feature->id]);
        Activity::factory()->count(3)->create(['parent_id' => $feature->id, 'status' => ActivityStatus::Todo]);

        expect($feature->tasksCount())->toBe(5);
    });
});

// ADR: Task-Feature relationship is now modelled as parent_id (Activity self-reference).
// $task->parent returns the parent epic; $task->hasParent() replaces isFromFeature().
describe('Task belongs to Feature', function () {
    test('task can belong to a feature', function () {
        $feature = Activity::factory()->epic()->create();
        $task = Activity::factory()->create(['parent_id' => $feature->id]);

        expect($task->parent)->toBeInstanceOf(Activity::class)
            ->and($task->parent->id)->toBe($feature->id)
            ->and($task->hasParent())->toBeTrue();
    });

    test('task can exist without a feature', function () {
        $task = Activity::factory()->create(['parent_id' => null]);

        expect($task->parent)->toBeNull()
            ->and($task->hasParent())->toBeFalse();
    });
});

// ADR: TimeEntry now uses activity_id (single FK). $entry->activity returns the linked activity.
describe('TimeEntry belongs to Feature', function () {
    test('time entry can belong to a feature', function () {
        $feature = Activity::factory()->epic()->create();
        $entry = TimeEntry::factory()->create([
            'activity_id' => $feature->id,
        ]);

        expect($entry->activity)->toBeInstanceOf(Activity::class)
            ->and($entry->activity->id)->toBe($feature->id);
    });

    test('time entry can belong to a task child of feature', function () {
        $feature = Activity::factory()->epic()->create();
        $task = Activity::factory()->create(['parent_id' => $feature->id]);
        $entry = TimeEntry::factory()->create([
            'activity_id' => $task->id,
        ]);

        expect($entry->activity)->toBeInstanceOf(Activity::class)
            ->and($entry->activity->id)->toBe($task->id)
            ->and($entry->activity->parent->id)->toBe($feature->id);
    });
});

describe('Project has Features', function () {
    test('project has many features', function () {
        $project = Project::factory()->create();
        Activity::factory()->epic()->count(3)->create(['project_id' => $project->id]);

        expect($project->features)->toHaveCount(3);
    });
});
