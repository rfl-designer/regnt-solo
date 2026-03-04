<?php

namespace App\Observers;

use App\Models\StakeholderIssue;
use App\Support\RealtimeEntitySync;

class StakeholderIssueRealtimeObserver
{
    /**
     * Handle the StakeholderIssue "created" event.
     */
    public function created(StakeholderIssue $stakeholderIssue): void
    {
        $this->touch();
    }

    /**
     * Handle the StakeholderIssue "updated" event.
     */
    public function updated(StakeholderIssue $stakeholderIssue): void
    {
        $this->touch();
    }

    /**
     * Handle the StakeholderIssue "deleted" event.
     */
    public function deleted(StakeholderIssue $stakeholderIssue): void
    {
        $this->touch();
    }

    /**
     * Handle the StakeholderIssue "restored" event.
     */
    public function restored(StakeholderIssue $stakeholderIssue): void
    {
        $this->touch();
    }

    /**
     * Handle the StakeholderIssue "force deleted" event.
     */
    public function forceDeleted(StakeholderIssue $stakeholderIssue): void
    {
        $this->touch();
    }

    private function touch(): void
    {
        RealtimeEntitySync::touch(RealtimeEntitySync::ENTITY_ISSUE);
    }
}
