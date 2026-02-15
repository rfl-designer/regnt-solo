<?php

namespace App\Observers;

use App\Models\Task;
use App\Models\TaskStatusChange;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     *
     * Records the initial status when a task is created.
     */
    public function created(Task $task): void
    {
        TaskStatusChange::create([
            'task_id' => $task->id,
            'from_status' => null,
            'to_status' => $task->status,
            'changed_at' => now(),
        ]);
    }

    /**
     * Handle the Task "updating" event.
     *
     * Records a status change when the status field is modified.
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
    }
}
