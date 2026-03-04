<?php

namespace App\Observers;

use App\Models\Project;
use App\Support\RealtimeEntitySync;

class ProjectRealtimeObserver
{
    /**
     * Handle the Project "created" event.
     */
    public function created(Project $project): void
    {
        $this->touch();
    }

    /**
     * Handle the Project "updated" event.
     */
    public function updated(Project $project): void
    {
        $this->touch();
    }

    /**
     * Handle the Project "deleted" event.
     */
    public function deleted(Project $project): void
    {
        $this->touch();
    }

    /**
     * Handle the Project "restored" event.
     */
    public function restored(Project $project): void
    {
        $this->touch();
    }

    /**
     * Handle the Project "force deleted" event.
     */
    public function forceDeleted(Project $project): void
    {
        $this->touch();
    }

    private function touch(): void
    {
        RealtimeEntitySync::touch(RealtimeEntitySync::ENTITY_PROJECT);
    }
}
