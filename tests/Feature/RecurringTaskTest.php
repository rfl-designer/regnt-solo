<?php

use App\Enums\RecurrenceFrequency;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Task;
use Carbon\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-02-17 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('RecurringTask Model', function (): void {
    it('creates a recurring task with daily frequency', function (): void {
        $recurring = RecurringTask::factory()->daily()->create([
            'title' => 'Daily Standup',
            'next_run' => Carbon::today(),
        ]);

        expect($recurring->frequency)->toBe(RecurrenceFrequency::Daily)
            ->and($recurring->is_active)->toBeTrue()
            ->and($recurring->isDue())->toBeTrue();
    });

    it('creates a recurring task with weekly frequency', function (): void {
        $recurring = RecurringTask::factory()->weekly(1)->create([
            'title' => 'Weekly Review',
        ]);

        expect($recurring->frequency)->toBe(RecurrenceFrequency::Weekly)
            ->and($recurring->day_of_week)->toBe(1);
    });

    it('creates a recurring task with monthly frequency', function (): void {
        $recurring = RecurringTask::factory()->monthly(15)->create([
            'title' => 'Monthly Report',
        ]);

        expect($recurring->frequency)->toBe(RecurrenceFrequency::Monthly)
            ->and($recurring->day_of_month)->toBe(15);
    });

    it('creates a task from recurring task', function (): void {
        $recurring = RecurringTask::factory()->daily()->create([
            'title' => 'Daily Task',
            'description' => 'Task description',
            'priority' => TaskPriority::High,
            'estimated_minutes' => 30,
        ]);

        $task = $recurring->createTask();

        expect($task)->toBeInstanceOf(Task::class)
            ->and($task->title)->toBe('Daily Task')
            ->and($task->description)->toBe('Task description')
            ->and($task->priority)->toBe(TaskPriority::High)
            ->and($task->status)->toBe(TaskStatus::Todo)
            ->and($task->recurring_task_id)->toBe($recurring->id)
            ->and($task->estimated_minutes)->toBe(30);
    });

    it('creates task with project from recurring task', function (): void {
        $project = Project::factory()->create();
        $recurring = RecurringTask::factory()->forProject($project)->create();

        $task = $recurring->createTask();

        expect($task->project_id)->toBe($project->id);
    });

    it('calculates next run for daily frequency', function (): void {
        $recurring = RecurringTask::factory()->daily()->create([
            'last_run' => Carbon::today(),
        ]);

        $nextRun = $recurring->calculateNextRun();

        expect($nextRun->toDateString())->toBe(Carbon::tomorrow()->toDateString());
    });

    it('calculates next run for weekly frequency', function (): void {
        $recurring = RecurringTask::factory()->weekly(1)->create([
            'last_run' => Carbon::today(),
        ]);

        $nextRun = $recurring->calculateNextRun();

        expect($nextRun->gt(Carbon::today()))->toBeTrue()
            ->and($nextRun->diffInDays(Carbon::today()))->toBeLessThanOrEqual(14);
    });

    it('calculates next run for monthly frequency', function (): void {
        $recurring = RecurringTask::factory()->monthly(15)->create([
            'last_run' => Carbon::parse('2026-02-15'),
        ]);

        $nextRun = $recurring->calculateNextRun();

        expect($nextRun->day)->toBe(15)
            ->and($nextRun->month)->toBe(3);
    });

    it('processes recurring task and updates next_run', function (): void {
        $recurring = RecurringTask::factory()->daily()->dueToday()->create([
            'title' => 'Process Me',
        ]);

        $task = $recurring->process();

        $recurring->refresh();

        expect($task->title)->toBe('Process Me')
            ->and($recurring->last_run->toDateString())->toBe(Carbon::today()->toDateString())
            ->and($recurring->next_run->toDateString())->toBe(Carbon::tomorrow()->toDateString());
    });

    it('scopes active recurring tasks', function (): void {
        RecurringTask::factory()->count(3)->create();
        RecurringTask::factory()->inactive()->count(2)->create();

        expect(RecurringTask::active()->count())->toBe(3);
    });

    it('scopes due recurring tasks', function (): void {
        RecurringTask::factory()->dueToday()->count(2)->create();
        RecurringTask::factory()->create(['next_run' => Carbon::tomorrow()]);

        expect(RecurringTask::due()->count())->toBe(2);
    });

    it('identifies overdue recurring tasks', function (): void {
        $overdue = RecurringTask::factory()->overdue()->create();
        $future = RecurringTask::factory()->create(['next_run' => Carbon::tomorrow()]);

        expect($overdue->isDue())->toBeTrue()
            ->and($future->isDue())->toBeFalse();
    });

    it('inactive recurring tasks are not due', function (): void {
        $recurring = RecurringTask::factory()->inactive()->dueToday()->create();

        expect($recurring->isDue())->toBeFalse();
    });
});

describe('Task relationships', function (): void {
    it('task belongs to recurring task', function (): void {
        $recurring = RecurringTask::factory()->create();
        $task = $recurring->createTask();

        expect($task->recurringTask)->toBeInstanceOf(RecurringTask::class)
            ->and($task->recurringTask->id)->toBe($recurring->id)
            ->and($task->isFromRecurring())->toBeTrue();
    });

    it('recurring task has many tasks', function (): void {
        $recurring = RecurringTask::factory()->create();
        $task1 = $recurring->createTask();
        $task2 = $recurring->createTask();

        expect($recurring->tasks)->toHaveCount(2)
            ->and($recurring->tasks->pluck('id')->all())->toContain($task1->id, $task2->id);
    });
});

describe('ProcessRecurringTasks Command', function (): void {
    it('processes due recurring tasks', function (): void {
        $recurring = RecurringTask::factory()->daily()->dueToday()->create([
            'title' => 'Due Today',
        ]);

        $this->artisan('soloboard:process-recurring')
            ->assertSuccessful()
            ->expectsOutputToContain('Criada: "Due Today"');

        expect(Task::where('recurring_task_id', $recurring->id)->count())->toBe(1);
    });

    it('does not process inactive recurring tasks', function (): void {
        $recurring = RecurringTask::factory()->inactive()->dueToday()->create();

        $this->artisan('soloboard:process-recurring')
            ->assertSuccessful()
            ->expectsOutputToContain('Nenhuma task recorrente para processar');

        expect(Task::where('recurring_task_id', $recurring->id)->count())->toBe(0);
    });

    it('does not process future recurring tasks', function (): void {
        RecurringTask::factory()->create(['next_run' => Carbon::tomorrow()]);

        $this->artisan('soloboard:process-recurring')
            ->assertSuccessful()
            ->expectsOutputToContain('Nenhuma task recorrente para processar');
    });

    it('dry run does not create tasks', function (): void {
        $recurring = RecurringTask::factory()->daily()->dueToday()->create([
            'title' => 'Dry Run Test',
        ]);

        $this->artisan('soloboard:process-recurring --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('[SIMULAÇÃO] Criaria: "Dry Run Test"');

        expect(Task::where('recurring_task_id', $recurring->id)->count())->toBe(0);
    });
});
