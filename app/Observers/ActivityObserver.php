<?php

namespace App\Observers;

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\DailyPlan;
use Carbon\Carbon;

class ActivityObserver
{
    /**
     * Handle the Activity "saving" event.
     *
     * Enforces the domain invariant that classifying an activity as "Data
     * fixa" requires a due date. This is the application-level choke point
     * for the guard — it fires on every model save regardless of origin
     * (Kanban, Task Modal, MCP tools, tinker), producing the friendly
     * {@see FixedDateRequiresDueDateException} with a PT-BR message.
     *
     * Limitation: this observer only fires for saves that go through an
     * Eloquent model instance. Bulk writes that skip model events —
     * `Activity::query()->update([...])`, `Activity::query()->upsert(...)`,
     * and raw `DB::table('activities')->update([...])` — never reach this
     * method. Those are backstopped by the `chk_activities_fixed_date_
     * requires_due_date` CHECK constraint added in
     * `2026_08_07_092633_add_fixed_date_due_date_check_to_activities_table`,
     * which is the actual source of truth for the invariant at the
     * database level; this method exists to turn that into a readable
     * PT-BR error before the query ever reaches the database.
     */
    public function saving(Activity $activity): void
    {
        if ($activity->service_class === ServiceClass::FixedDate && $activity->due_date === null) {
            throw new FixedDateRequiresDueDateException;
        }
    }

    /**
     * Handle the Activity "creating" event.
     *
     * Sets completed_at when an activity is created directly with status done.
     */
    public function creating(Activity $activity): void
    {
        if ($activity->status === ActivityStatus::Done && $activity->completed_at === null) {
            $activity->completed_at = now();
        }

        $this->clearDirectClientWhenProjectPresent($activity);
    }

    /**
     * Handle the Activity "created" event.
     *
     * Records the initial status when an activity is created.
     * Auto-adds activity to today's daily plan if due_date is today.
     */
    public function created(Activity $activity): void
    {
        if ($activity->status !== null) {
            ActivityStatusChange::create([
                'activity_id' => $activity->id,
                'from_status' => null,
                'to_status' => $activity->status,
                'changed_at' => now(),
            ]);
        }

        $this->addToDailyPlanIfDueToday($activity);
    }

    /**
     * Handle the Activity "updating" event.
     *
     * Records a status change when the status field is modified.
     * Auto-adds activity to today's daily plan if due_date changes to today.
     * Syncs pivot_completed_at when status changes to/from done.
     */
    public function updating(Activity $activity): void
    {
        $this->clearDirectClientWhenProjectPresent($activity);

        if ($activity->isDirty('status') && $activity->status !== null) {
            ActivityStatusChange::create([
                'activity_id' => $activity->id,
                'from_status' => $activity->getOriginal('status'),
                'to_status' => $activity->status,
                'changed_at' => now(),
            ]);

            $this->syncDailyPlanCompletedAt($activity);
        }

        if ($activity->isDirty('due_date')) {
            $this->addToDailyPlanIfDueToday($activity);
        }
    }

    /**
     * Sync the pivot completed_at when status changes to/from done.
     */
    private function syncDailyPlanCompletedAt(Activity $activity): void
    {
        $plan = DailyPlan::query()
            ->whereDate('date', Carbon::today())
            ->first();

        if (! $plan) {
            return;
        }

        if (! $plan->tasks()->where('activities.id', $activity->id)->exists()) {
            return;
        }

        $newStatus = $activity->status;
        $oldStatus = $activity->getOriginal('status');

        if ($newStatus === ActivityStatus::Done && $oldStatus !== ActivityStatus::Done) {
            $plan->tasks()->updateExistingPivot($activity->id, ['completed_at' => now()]);
        } elseif ($newStatus !== ActivityStatus::Done && $oldStatus === ActivityStatus::Done) {
            $plan->tasks()->updateExistingPivot($activity->id, ['completed_at' => null]);
        }
    }

    /**
     * Add the activity to today's daily plan if due_date is today.
     */
    private function addToDailyPlanIfDueToday(Activity $activity): void
    {
        if ($activity->due_date === null || ! $activity->due_date->isToday()) {
            return;
        }

        $plan = DailyPlan::getOrCreateForDate(Carbon::today());

        if ($plan->tasks()->where('activities.id', $activity->id)->exists()) {
            return;
        }

        $maxOrder = $plan->tasks()->max('daily_plan_activity.sort_order') ?? -1;
        $plan->tasks()->attach($activity->id, ['sort_order' => $maxOrder + 1]);
    }

    /**
     * Enforce the invariant that an activity's project link and direct client
     * link never coexist: whenever a project is set, clear the direct client.
     */
    private function clearDirectClientWhenProjectPresent(Activity $activity): void
    {
        if ($activity->project_id !== null && $activity->client_id !== null) {
            $activity->client_id = null;
        }
    }
}
