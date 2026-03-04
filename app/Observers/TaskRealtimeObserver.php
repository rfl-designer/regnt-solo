<?php

namespace App\Observers;

use App\Models\Task;
use App\Support\RealtimeEntitySync;

class TaskRealtimeObserver
{
    /**
     * Handle the Task "created" event.
     */
    public function created(Task $task): void
    {
        $this->touch();
    }

    /**
     * Handle the Task "updated" event.
     */
    public function updated(Task $task): void
    {
        $this->touch();
    }

    /**
     * Handle the Task "deleted" event.
     */
    public function deleted(Task $task): void
    {
        $this->touch();
    }

    /**
     * Handle the Task "restored" event.
     */
    public function restored(Task $task): void
    {
        $this->touch();
    }

    /**
     * Handle the Task "force deleted" event.
     */
    public function forceDeleted(Task $task): void
    {
        $this->touch();
    }

    private function touch(): void
    {
        RealtimeEntitySync::touch(RealtimeEntitySync::ENTITY_TASK);
    }
}
