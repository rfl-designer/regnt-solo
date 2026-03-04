<?php

namespace App\Observers;

use App\Models\Feature;
use App\Support\RealtimeEntitySync;

class FeatureRealtimeObserver
{
    /**
     * Handle the Feature "created" event.
     */
    public function created(Feature $feature): void
    {
        $this->touch();
    }

    /**
     * Handle the Feature "updated" event.
     */
    public function updated(Feature $feature): void
    {
        $this->touch();
    }

    /**
     * Handle the Feature "deleted" event.
     */
    public function deleted(Feature $feature): void
    {
        $this->touch();
    }

    /**
     * Handle the Feature "restored" event.
     */
    public function restored(Feature $feature): void
    {
        $this->touch();
    }

    /**
     * Handle the Feature "force deleted" event.
     */
    public function forceDeleted(Feature $feature): void
    {
        $this->touch();
    }

    private function touch(): void
    {
        RealtimeEntitySync::touch(RealtimeEntitySync::ENTITY_FEATURE);
    }
}
