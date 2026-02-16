<?php

namespace App\Observers;

use App\Models\DailyPlan;
use App\Models\Task;
use App\Models\TaskStatusChange;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     *
     * Records the initial status when a task is created.
     * Auto-adds task to today's daily plan if due_date is today.
     */
    public function created(Task $task): void
    {
        TaskStatusChange::create([
            'task_id' => $task->id,
            'from_status' => null,
            'to_status' => $task->status,
            'changed_at' => now(),
        ]);

        $this->addToDailyPlanIfDueToday($task);
    }

    /**
     * Handle the Task "updating" event.
     *
     * Records a status change when the status field is modified.
     * Auto-adds task to today's daily plan if due_date changes to today.
     */
    public function updating(Task $task): void
    {
        if ($task->isDirty('status')) {
            TaskStatusChange::create([
                'task_id' => $task->id,
                'from_status' => $task->getOriginal('status'),
                'to_status' => $task->status,
                'changed_at' => now(),
            ]);
        }

        if ($task->isDirty('due_date')) {
            $this->addToDailyPlanIfDueToday($task);
        }
    }

    /**
     * Add the task to today's daily plan if due_date is today.
     */
    private function addToDailyPlanIfDueToday(Task $task): void
    {
        if ($task->due_date === null || ! $task->due_date->isToday()) {
            return;
        }

        $plan = DailyPlan::getOrCreateForDate(\Carbon\Carbon::today());

        if ($plan->tasks()->where('tasks.id', $task->id)->exists()) {
            return;
        }

        $maxOrder = $plan->tasks()->max('daily_plan_task.sort_order') ?? -1;
        $plan->tasks()->attach($task->id, ['sort_order' => $maxOrder + 1]);
    }
}
