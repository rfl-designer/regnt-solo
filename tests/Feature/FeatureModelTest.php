<?php

use App\Enums\FeaturePriority;
use App\Enums\FeatureStatus;
use App\Enums\TaskStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Feature Model', function () {
    test('can create a feature', function () {
        $feature = Feature::factory()->create([
            'title' => 'Auth com 2FA',
            'slug' => 'auth-com-2fa',
            'priority' => FeaturePriority::High,
        ]);

        expect($feature)->toBeInstanceOf(Feature::class)
            ->and($feature->title)->toBe('Auth com 2FA')
            ->and($feature->priority)->toBe(FeaturePriority::High)
            ->and($feature->slug)->toBe('auth-com-2fa');
    });

    test('auto generates slug from title', function () {
        $feature = Feature::factory()->create([
            'title' => 'Dashboard de Analytics',
            'slug' => null,
        ]);

        expect($feature->slug)->toBe('dashboard-de-analytics');
    });

    test('belongs to a project', function () {
        $project = Project::factory()->create();
        $feature = Feature::factory()->create(['project_id' => $project->id]);

        expect($feature->project)->toBeInstanceOf(Project::class)
            ->and($feature->project->id)->toBe($project->id);
    });

    test('can be created without a project', function () {
        $feature = Feature::factory()->create(['project_id' => null]);

        expect($feature->project)->toBeNull();
    });

    test('has many tasks', function () {
        $feature = Feature::factory()->create();
        Task::factory()->count(3)->create(['feature_id' => $feature->id]);

        expect($feature->tasks)->toHaveCount(3);
    });

    test('has many time entries', function () {
        $feature = Feature::factory()->create();
        TimeEntry::factory()->count(2)->create(['feature_id' => $feature->id, 'task_id' => null]);

        expect($feature->timeEntries)->toHaveCount(2);
    });
});

describe('Feature Status (persisted)', function () {
    test('status defaults to draft on creation', function () {
        $feature = Feature::factory()->create();

        expect($feature->status)->toBe(FeatureStatus::Draft);
        $this->assertDatabaseHas('features', ['id' => $feature->id, 'status' => 'draft']);
    });

    test('status can be set to any FeatureStatus value', function () {
        $feature = Feature::factory()->create(['status' => FeatureStatus::Doing]);

        expect($feature->status)->toBe(FeatureStatus::Doing);
        $this->assertDatabaseHas('features', ['id' => $feature->id, 'status' => 'doing']);
    });

    test('status persists when updated', function () {
        $feature = Feature::factory()->create(['status' => FeatureStatus::Backlog]);
        $feature->update(['status' => FeatureStatus::Todo]);
        $feature->refresh();

        expect($feature->status)->toBe(FeatureStatus::Todo);
        $this->assertDatabaseHas('features', ['id' => $feature->id, 'status' => 'todo']);
    });

    test('status is independent of tasks', function () {
        // Feature manually set to Doing, but all tasks are Done → status stays Doing
        $feature = Feature::factory()->create(['status' => FeatureStatus::Doing]);
        Task::factory()->done()->create(['feature_id' => $feature->id]);
        Task::factory()->done()->create(['feature_id' => $feature->id]);

        $feature->refresh();

        expect($feature->status)->toBe(FeatureStatus::Doing)
            ->and($feature->progress)->toBe(100);
    });
});

describe('Feature Progress', function () {
    test('progress is 0 when no tasks', function () {
        $feature = Feature::factory()->create();

        expect($feature->progress)->toBe(0);
    });

    test('progress is 0 when no tasks are done', function () {
        $feature = Feature::factory()->create();
        Task::factory()->count(3)->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        expect($feature->progress)->toBe(0);
    });

    test('progress is 50 when half tasks are done', function () {
        $feature = Feature::factory()->create();
        Task::factory()->done()->count(2)->create(['feature_id' => $feature->id]);
        Task::factory()->count(2)->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        expect($feature->progress)->toBe(50);
    });

    test('progress is 100 when all tasks are done', function () {
        $feature = Feature::factory()->create();
        Task::factory()->done()->count(3)->create(['feature_id' => $feature->id]);

        expect($feature->progress)->toBe(100);
    });
});

describe('Feature Total Time', function () {
    test('total time is 0 when no time entries', function () {
        $feature = Feature::factory()->create();

        expect($feature->total_time)->toBe(0.0);
    });

    test('total time sums completed time entries', function () {
        $feature = Feature::factory()->create();
        TimeEntry::factory()->create([
            'feature_id' => $feature->id,
            'task_id' => null,
            'started_at' => now()->subMinutes(60),
            'stopped_at' => now(),
        ]);
        TimeEntry::factory()->create([
            'feature_id' => $feature->id,
            'task_id' => null,
            'started_at' => now()->subMinutes(30),
            'stopped_at' => now(),
        ]);

        expect($feature->total_time)->toBeGreaterThanOrEqual(89.0)
            ->and($feature->total_time)->toBeLessThanOrEqual(91.0);
    });

    test('total time excludes running entries', function () {
        $feature = Feature::factory()->create();
        TimeEntry::factory()->create([
            'feature_id' => $feature->id,
            'task_id' => null,
            'started_at' => now()->subMinutes(60),
            'stopped_at' => now(),
        ]);
        TimeEntry::factory()->create([
            'feature_id' => $feature->id,
            'task_id' => null,
            'started_at' => now()->subMinutes(30),
            'stopped_at' => null,
        ]);

        expect($feature->total_time)->toBeGreaterThanOrEqual(59.0)
            ->and($feature->total_time)->toBeLessThanOrEqual(61.0);
    });
});

describe('Feature Timer', function () {
    test('is not running when no time entries', function () {
        $feature = Feature::factory()->create();

        expect($feature->isRunning())->toBeFalse();
    });

    test('is running when has a running time entry', function () {
        $feature = Feature::factory()->create();
        TimeEntry::factory()->create([
            'feature_id' => $feature->id,
            'task_id' => null,
            'stopped_at' => null,
        ]);

        expect($feature->isRunning())->toBeTrue();
    });

    test('can start a timer', function () {
        $feature = Feature::factory()->create();

        $entry = $feature->startTimer();

        expect($entry)->toBeInstanceOf(TimeEntry::class)
            ->and($entry->feature_id)->toBe($feature->id)
            ->and($entry->task_id)->toBeNull()
            ->and($entry->stopped_at)->toBeNull();
    });

    test('starting timer stops other running timers', function () {
        $feature1 = Feature::factory()->create();
        $feature2 = Feature::factory()->create();

        $entry1 = $feature1->startTimer();
        $entry2 = $feature2->startTimer();

        $entry1->refresh();

        expect($entry1->stopped_at)->not->toBeNull()
            ->and($entry2->stopped_at)->toBeNull();
    });

    test('can start a focus timer', function () {
        $feature = Feature::factory()->create();

        $entry = $feature->startTimer(focus: true);

        expect($entry->is_focus_session)->toBeTrue();
    });

    test('can stop a timer', function () {
        $feature = Feature::factory()->create();
        $entry = $feature->startTimer();

        $stoppedEntry = $feature->stopTimer('Work completed');

        expect($stoppedEntry)->not->toBeNull()
            ->and($stoppedEntry->stopped_at)->not->toBeNull()
            ->and($stoppedEntry->notes)->toBe('Work completed');
    });

    test('stopTimer returns null when no running entry', function () {
        $feature = Feature::factory()->create();

        $result = $feature->stopTimer();

        expect($result)->toBeNull();
    });

    test('stopTimer can save notes as null', function () {
        $feature = Feature::factory()->create();
        $entry = $feature->startTimer();

        $stoppedEntry = $feature->stopTimer();

        expect($stoppedEntry)->not->toBeNull()
            ->and($stoppedEntry->stopped_at)->not->toBeNull()
            ->and($stoppedEntry->notes)->toBeNull();
    });

    test('runningEntry returns the running time entry', function () {
        $feature = Feature::factory()->create();
        $entry = $feature->startTimer();

        $runningEntry = $feature->runningEntry();

        expect($runningEntry)->not->toBeNull()
            ->and($runningEntry->id)->toBe($entry->id);
    });

    test('runningEntry returns null when no running entry', function () {
        $feature = Feature::factory()->create();

        $runningEntry = $feature->runningEntry();

        expect($runningEntry)->toBeNull();
    });
});

describe('Feature Helper Methods', function () {
    test('counts completed tasks', function () {
        $feature = Feature::factory()->create();
        Task::factory()->done()->count(2)->create(['feature_id' => $feature->id]);
        Task::factory()->count(3)->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        expect($feature->completedTasksCount())->toBe(2);
    });

    test('counts total tasks', function () {
        $feature = Feature::factory()->create();
        Task::factory()->done()->count(2)->create(['feature_id' => $feature->id]);
        Task::factory()->count(3)->create(['feature_id' => $feature->id, 'status' => TaskStatus::Todo]);

        expect($feature->tasksCount())->toBe(5);
    });
});

describe('Task belongs to Feature', function () {
    test('task can belong to a feature', function () {
        $feature = Feature::factory()->create();
        $task = Task::factory()->create(['feature_id' => $feature->id]);

        expect($task->feature)->toBeInstanceOf(Feature::class)
            ->and($task->feature->id)->toBe($feature->id)
            ->and($task->isFromFeature())->toBeTrue();
    });

    test('task can exist without a feature', function () {
        $task = Task::factory()->create(['feature_id' => null]);

        expect($task->feature)->toBeNull()
            ->and($task->isFromFeature())->toBeFalse();
    });
});

describe('TimeEntry belongs to Feature', function () {
    test('time entry can belong to a feature', function () {
        $feature = Feature::factory()->create();
        $entry = TimeEntry::factory()->create([
            'feature_id' => $feature->id,
            'task_id' => null,
        ]);

        expect($entry->feature)->toBeInstanceOf(Feature::class)
            ->and($entry->feature->id)->toBe($feature->id);
    });

    test('time entry can have both feature and task', function () {
        $feature = Feature::factory()->create();
        $task = Task::factory()->create(['feature_id' => $feature->id]);
        $entry = TimeEntry::factory()->create([
            'feature_id' => $feature->id,
            'task_id' => $task->id,
        ]);

        expect($entry->feature)->toBeInstanceOf(Feature::class)
            ->and($entry->task)->toBeInstanceOf(Task::class);
    });
});

describe('Project has Features', function () {
    test('project has many features', function () {
        $project = Project::factory()->create();
        Feature::factory()->count(3)->create(['project_id' => $project->id]);

        expect($project->features)->toHaveCount(3);
    });
});
