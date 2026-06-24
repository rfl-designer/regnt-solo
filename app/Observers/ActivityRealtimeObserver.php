<?php

namespace App\Observers;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Support\RealtimeEntitySync;

class ActivityRealtimeObserver
{
    /**
     * Handle the Activity "created" event.
     */
    public function created(Activity $activity): void
    {
        $this->touch($activity);
    }

    /**
     * Handle the Activity "updated" event.
     */
    public function updated(Activity $activity): void
    {
        $this->touch($activity);
    }

    /**
     * Handle the Activity "deleted" event.
     */
    public function deleted(Activity $activity): void
    {
        $this->touch($activity);
    }

    /**
     * Handle the Activity "restored" event.
     */
    public function restored(Activity $activity): void
    {
        $this->touch($activity);
    }

    /**
     * Handle the Activity "force deleted" event.
     */
    public function forceDeleted(Activity $activity): void
    {
        $this->touch($activity);
    }

    private function touch(Activity $activity): void
    {
        $entity = $activity->type === ActivityType::Epic
            ? RealtimeEntitySync::ENTITY_FEATURE
            : RealtimeEntitySync::ENTITY_TASK;

        RealtimeEntitySync::touch($entity);
    }
}
